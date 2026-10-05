<?php

declare(strict_types=1);

namespace App\Tests\Functional\Applications;

use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Application\Port\ApplicationIdGenerator;
use App\Applications\Application\Port\EventPublisher;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Event\ApplicationSubmitted;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ApplicationSubmissionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private DocumentManager $documentManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $documentManager = self::getContainer()->get(DocumentManager::class);
        self::assertInstanceOf(DocumentManager::class, $documentManager);
        $this->documentManager = $documentManager;
        $this->cleanApplications();
    }

    protected function tearDown(): void
    {
        $this->cleanApplications();
        parent::tearDown();
    }

    public function testItDisplaysAllJobsAndTheApplicationForm(): void
    {
        $crawler = $this->client->request('GET', '/apply');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#validation-summary');
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorTextContains('h1', 'Apply for a role');
        self::assertSelectorTextContains('body', 'Backend Developer');
        self::assertSelectorTextContains('body', 'Frontend Developer');
        self::assertSelectorTextContains('body', 'Fullstack Developer');
        self::assertCount(1, $crawler->filter('textarea[name="application_submission[cvText]"]'));
    }

    public function testValidSubmissionPersistsAndDispatchesThenShowsConfirmation(): void
    {
        $crawler = $this->submitValidForm();

        self::assertResponseRedirects();
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $flash = $session->getFlashBag()->peek('application_submission');
        self::assertCount(1, $flash);
        self::assertSame(['applicationId', 'analysisQueued'], array_keys($flash[0]));
        self::assertStringNotContainsString('ada@example.test', serialize($session->all()));
        self::assertStringNotContainsString('Private CV text', serialize($session->all()));
        self::assertSame(1, $this->countApplications());
        self::assertCount(1, $this->transport()->getSent());

        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $crawler = $this->client->followRedirect();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorTextContains('h1', 'Application received');
        self::assertStringContainsString('Mock analysis is pending', $crawler->text());
    }

    public function testInvalidFieldsRenderErrorsAndPreserveSubmittedValuesWithoutUsingSession(): void
    {
        $crawler = $this->client->request('GET', '/apply');
        $form = $crawler->selectButton('Submit application')->form([
            'application_submission[fullName]' => '  ',
            'application_submission[email]' => 'invalid-email',
            'application_submission[phone]' => str_repeat('1', 31),
            'application_submission[jobId]' => 'backend-developer',
            'application_submission[notes]' => str_repeat('n', 2_001),
            'application_submission[cvText]' => '<script>Preserve this private CV</script>',
        ]);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter your full name.', $crawler->text());
        self::assertStringContainsString('Enter a valid email address.', $crawler->text());
        self::assertStringContainsString('Phone must be at most 30 characters.', $crawler->text());
        self::assertStringContainsString('Notes must be at most 2000 characters.', $crawler->text());
        self::assertInputValueSame('application_submission[email]', 'invalid-email');
        self::assertSame('<script>Preserve this private CV</script>', $crawler->filter('textarea[name="application_submission[cvText]"]')->text());
        self::assertStringNotContainsString('<script>Preserve this private CV</script>', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Preserve this private CV', serialize($this->client->getRequest()->getSession()->all()));
        self::assertSame(0, $this->countApplications());
    }

    public function testAllEmptyBrowserSubmissionRendersRequiredErrorsWithoutInvokingSubmission(): void
    {
        $this->client->disableReboot();
        $applicationIds = new RecordingApplicationIdGenerator();
        self::getContainer()->set(ApplicationIdGenerator::class, $applicationIds);
        $crawler = $this->client->request('GET', '/apply');
        $form = $crawler->selectButton('Submit application')->form([
            'application_submission[fullName]' => '',
            'application_submission[email]' => '',
            'application_submission[phone]' => '',
            'application_submission[jobId]' => '',
            'application_submission[notes]' => '',
            'application_submission[cvText]' => '',
        ]);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->client->getResponse()->isRedirection());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertSelectorExists('#validation-summary[tabindex="-1"]');
        foreach (['fullName', 'email', 'jobId', 'cvText'] as $name) {
            $id = 'application_submission_'.$name;
            self::assertSelectorExists('#validation-summary a[href="#'.$id.'"]');
            self::assertSelectorExists('#'.$id.'[aria-invalid="true"]');
            self::assertSelectorExists('#'.$id.'[aria-describedby="'.$id.'_error1"]');
            self::assertSelectorExists('#'.$id.'_errors li');
        }

        self::assertStringContainsString('Enter your full name.', $crawler->text());
        self::assertStringContainsString('Enter your email address.', $crawler->text());
        self::assertStringContainsString('Choose a job.', $crawler->text());
        self::assertStringContainsString('Paste your CV text.', $crawler->text());
        self::assertStringNotContainsString('The CSRF token is invalid.', $crawler->text());
        self::assertSame(0, $applicationIds->calls);
        self::assertSame(0, $this->countApplications());
        self::assertCount(0, $this->transport()->getSent());

        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame([], $session->getFlashBag()->peek('application_submission'));
    }

    public function testEmailRejectedByExistingDomainRulesReturnsFieldValidationInsteadOf500(): void
    {
        $crawler = $this->client->request('GET', '/apply');
        $form = $crawler->selectButton('Submit application')->form([
            'application_submission[fullName]' => 'Fictional Candidate',
            'application_submission[email]' => str_repeat('a', 65).'@example.test',
            'application_submission[jobId]' => 'backend-developer',
            'application_submission[cvText]' => 'Fictional CV',
        ]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#application_submission_email_errors', 'Enter a valid email address.');
        self::assertSelectorExists('#validation-summary a[href="#application_submission_email"]');
        self::assertSame(0, $this->countApplications());
    }

    public function testEveryTextLimitIsEnforcedByTheForm(): void
    {
        $crawler = $this->client->request('GET', '/apply');
        $form = $crawler->selectButton('Submit application')->form([
            'application_submission[fullName]' => str_repeat('n', 151),
            'application_submission[email]' => str_repeat('e', 243).'@example.test',
            'application_submission[phone]' => str_repeat('1', 31),
            'application_submission[jobId]' => 'backend-developer',
            'application_submission[notes]' => str_repeat('n', 2_001),
            'application_submission[cvText]' => str_repeat('c', 30_001),
        ]);

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Full name must be at most 150 characters.', $crawler->text());
        self::assertStringContainsString('Email must be at most 254 characters.', $crawler->text());
        self::assertStringContainsString('Phone must be at most 30 characters.', $crawler->text());
        self::assertStringContainsString('Notes must be at most 2000 characters.', $crawler->text());
        self::assertStringContainsString('CV text must be at most 30000 characters.', $crawler->text());
        self::assertSame(0, $this->countApplications());
    }

    public function testManipulatedUnknownJobIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/apply');
        $values = $this->validValues();
        $values['application_submission']['_token'] = $crawler->filter('input[name="application_submission[_token]"]')->attr('value');
        $values['application_submission']['jobId'] = 'unknown-job';

        $crawler = $this->client->request('POST', '/apply', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('selected choice is invalid', strtolower($crawler->text()));
        self::assertSame(0, $this->countApplications());
    }

    public function testInvalidCsrfTokenStoresNothing(): void
    {
        $values = $this->validValues();
        $values['application_submission']['_token'] = 'invalid';

        $crawler = $this->client->request('POST', '/apply', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('csrf token is invalid', strtolower($crawler->text()));
        self::assertSelectorExists('#validation-summary');
        self::assertSelectorNotExists('#validation-summary a[href*="_token"]');
        self::assertStringNotContainsString('Enter your full name.', $crawler->text());
        self::assertSame(0, $this->countApplications());
    }

    public function testExpectedDispatchFailureStoresOnePendingApplicationAndShowsWarning(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(EventPublisher::class, new ExpectedFailingEventPublisher());

        $this->submitValidForm();

        self::assertResponseRedirects();
        self::assertSame(1, $this->countApplications());
        $document = $this->firstApplication();
        self::assertSame('pending', $document->enrichmentStatus);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('automatic analysis could not be started', $crawler->text());
        self::assertStringContainsString('do not need to submit it again', $crawler->text());
    }

    private function submitValidForm(): Crawler
    {
        $crawler = $this->client->request('GET', '/apply');
        $form = $crawler->selectButton('Submit application')->form([
            'application_submission[fullName]' => 'Ada Lovelace',
            'application_submission[email]' => 'ada@example.test',
            'application_submission[phone]' => '+44 123 456',
            'application_submission[jobId]' => 'backend-developer',
            'application_submission[notes]' => 'Available immediately.',
            'application_submission[cvText]' => 'Private CV text',
        ]);

        return $this->client->submit($form);
    }

    /** @return array{application_submission: array<string, string>} */
    private function validValues(): array
    {
        return ['application_submission' => [
            'fullName' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'phone' => '',
            'jobId' => 'backend-developer',
            'notes' => '',
            'cvText' => 'Private CV text',
            'submit' => '',
        ]];
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.enrichment_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function countApplications(): int
    {
        $database = $this->databaseName();
        $collection = $this->documentManager->getClassMetadata(ApplicationDocument::class)->getCollection();

        return $this->documentManager->getClient()->selectCollection($database, $collection)->countDocuments();
    }

    private function firstApplication(): ApplicationDocument
    {
        $document = $this->documentManager->getRepository(ApplicationDocument::class)->findOneBy([]);
        self::assertInstanceOf(ApplicationDocument::class, $document);

        return $document;
    }

    private function cleanApplications(): void
    {
        $collection = $this->documentManager->getClassMetadata(ApplicationDocument::class)->getCollection();
        $this->documentManager->getClient()->selectCollection($this->databaseName(), $collection)->deleteMany([]);
        $this->documentManager->clear();
    }

    private function databaseName(): string
    {
        $database = $this->documentManager->getConfiguration()->getDefaultDB();
        self::assertNotNull($database);

        return $database;
    }
}

final class ExpectedFailingEventPublisher implements EventPublisher
{
    public function publish(ApplicationSubmitted $event): void
    {
        throw new EnrichmentDispatchFailed('Expected Messenger transport failure.');
    }
}

final class RecordingApplicationIdGenerator implements ApplicationIdGenerator
{
    public int $calls = 0;

    public function generate(): ApplicationId
    {
        ++$this->calls;

        return ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');
    }
}
