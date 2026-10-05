<?php

declare(strict_types=1);

namespace App\Tests\Functional\Applications;

use App\Applications\Application\Port\ApplicationReadRepository;
use App\Applications\Application\Port\EventPublisher;
use App\Tests\Support\ApplicationReadFixtures;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ApplicationBrowseControllerTest extends WebTestCase
{
    use ApplicationReadFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(DocumentManager::class);
        self::assertInstanceOf(DocumentManager::class, $manager);
        $this->initializeReadFixtures($manager);
    }

    protected function tearDown(): void
    {
        $this->cleanReadFixtures();
        parent::tearDown();
    }

    public function testListShowsStatusesZeroScoresAndLinksWithoutChangingDataOrDispatching(): void
    {
        $ids = [];
        foreach (['pending', 'processing', 'completed', 'failed'] as $state) {
            $document = $this->insertApplication([
                'enrichmentStatus' => $state,
                'enrichmentScore' => 'completed' === $state ? 0 : null,
                'enrichmentSummary' => 'completed' === $state ? 'Mock analysis: No skills detected.' : null,
                'enrichedAt' => 'completed' === $state ? new UTCDateTime() : null,
                'processingAttemptId' => 'private-lease', 'processingStartedAt' => new UTCDateTime(),
            ]);
            $ids[] = $document['_id'];
        }
        $publisher = $this->createMock(EventPublisher::class);
        $publisher->expects(self::never())->method('publish');
        self::getContainer()->set(EventPublisher::class, $publisher);
        $before = $this->snapshot();
        $crawler = $this->client->request('GET', '/applications', ['search' => $this->correlation]);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('tbody tr'));
        foreach (['Received', 'Pending', 'Processing', 'Completed', 'Failed', '0/100', 'Not available yet', 'Showing up to 100'] as $label) {
            self::assertSelectorTextContains('body', $label);
        }
        foreach ($ids as $id) {
            self::assertCount(1, $crawler->filter('a[href^="/applications/'.$id.'"]'));
            $this->client->request('GET', '/applications/'.$id, ['search' => $this->correlation]);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('private-lease', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('processingAttemptId', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('processingStartedAt', (string) $this->client->getResponse()->getContent());
        }
        self::assertSame($before, $this->snapshot());
        foreach (['enrichment_async', 'failed', 'infrastructure_async'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            self::assertSame([], $transport->getSent());
        }
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
    }

    public function testFiltersAndSearchCanBeCombinedAndPreservedInBackLinks(): void
    {
        $match = $this->insertApplication(['candidateFullName' => 'Álvaro '.$this->correlation, 'enrichmentStatus' => 'failed']);
        $this->insertApplication(['jobId' => 'frontend-developer']);
        $parameters = ['search' => 'álvaro '.$this->correlation, 'job' => 'backend-developer', 'applicationStatus' => 'received', 'enrichmentStatus' => 'failed'];
        $crawler = $this->client->request('GET', '/applications', $parameters + ['returnUrl' => 'https://example.test/untrusted']);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame($match['_id'], $crawler->filter('tbody tr')->attr('data-application-id'));
        $link = $crawler->filter('tbody a')->link();
        $crawler = $this->client->click($link);
        self::assertResponseIsSuccessful();
        $back = $crawler->filter('.back-to-list')->attr('href');
        self::assertNotNull($back);
        self::assertSame('/applications?'.http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986), $back);
        self::assertStringNotContainsString('returnUrl', (string) $this->client->getResponse()->getContent());
        $this->client->click($crawler->filter('.back-to-list')->link());
        self::assertSelectorCount(1, 'tbody tr');
    }

    public function testEveryFilterWorksIndependently(): void
    {
        $this->insertApplication();
        $this->insertApplication(['jobId' => 'frontend-developer', 'enrichmentStatus' => 'failed']);
        foreach ([['job' => 'backend-developer'], ['enrichmentStatus' => 'pending']] as $criteria) {
            $this->client->request('GET', '/applications', ['search' => $this->correlation] + $criteria);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, 'tbody tr');
        }
        $this->client->request('GET', '/applications', ['search' => $this->correlation, 'applicationStatus' => 'received']);
        self::assertSelectorCount(2, 'tbody tr');
    }

    public function testRegexCharactersAndBackslashesAreLiteralInHttpSearch(): void
    {
        $name = $this->correlation.' .* (?i) C:\\Demo';
        $this->insertApplication(['candidateFullName' => $name]);
        $this->insertApplication();
        $this->client->request('GET', '/applications', ['search' => '.* (?i) c:\\demo']);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tbody tr');
        self::assertSelectorTextContains('tbody', $name);
    }

    /** @param array<string, mixed> $parameters */
    #[DataProvider('invalidParameters')]
    public function testInvalidParametersReturn400WithoutReadingOrDispatching(array $parameters): void
    {
        $repository = $this->createMock(ApplicationReadRepository::class);
        $repository->expects(self::never())->method('search');
        $repository->expects(self::never())->method('findDetail');
        self::getContainer()->set(ApplicationReadRepository::class, $repository);
        $crawler = $this->client->request('GET', '/applications', $parameters);
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('body', 'Correct the filter errors');
        self::assertCount(0, $crawler->filter('tbody tr'));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidParameters(): iterable
    {
        yield 'unknown job' => [['job' => 'unknown-job']];
        yield 'application status' => [['applicationStatus' => 'hired']];
        yield 'enrichment status' => [['enrichmentStatus' => 'invalid']];
        yield 'array injection' => [['search' => ['$regex' => '.*']]];
        yield 'job array' => [['job' => ['backend-developer']]];
        yield 'invalid UTF-8' => [['search' => "\xFF"]];
        yield 'too long' => [['search' => str_repeat('á', 255)]];
    }

    public function testEmptyResultsAndUnfilteredList(): void
    {
        $this->client->request('GET', '/applications');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/applications', ['search' => 'missing-'.$this->correlation]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'No applications found.');
    }

    public function testDetailEscapesAllContentPreservesLineBreaksAndDisplaysStoredResults(): void
    {
        $script = '<script>alert("fictional")</script>';
        $document = $this->insertApplication([
            'candidateFullName' => $this->correlation.' '.$script,
            'notes' => $script."\nNotes second line",
            'cvText' => $script."\nCV second line",
            'enrichmentStatus' => 'completed', 'enrichmentScore' => 0,
            'enrichmentSummary' => 'Mock analysis: '.$script, 'enrichedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-05T10:01:00Z')),
        ]);
        $this->client->request('GET', '/applications', ['search' => $this->correlation]);
        self::assertSelectorNotExists('script');
        self::assertStringNotContainsString($script, (string) $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', '/applications/'.$document['_id']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('script');
        self::assertStringNotContainsString($script, (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('&lt;script&gt;', (string) $this->client->getResponse()->getContent());
        self::assertSame($document['cvText'], $crawler->filter('.cv-text')->text(null, false));
        self::assertSame($document['notes'], $crawler->filter('.notes')->text(null, false));
        self::assertSelectorTextContains('.analysis-score', '0/100');
        self::assertSelectorTextContains('body', '2026-10-05 10:01:00');
        self::assertSelectorTextContains('body', 'Not provided');
        self::assertSame('/applications', $crawler->filter('.back-to-list')->attr('href'));
    }

    public function testUnfinishedDetailHasNoInventedResultsOrRetryControls(): void
    {
        foreach (['pending' => 'awaiting processing', 'processing' => 'Analysis is processing', 'failed' => 'could not be completed'] as $state => $copy) {
            $document = $this->insertApplication(['enrichmentStatus' => $state]);
            $this->client->request('GET', '/applications/'.$document['_id']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $copy);
            self::assertSelectorNotExists('.analysis-summary');
            self::assertSelectorNotExists('.notes');
            self::assertSelectorNotExists('button');
            self::assertStringNotContainsString('/100', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testInvalidAndMissingIdsAndUntrustedReturnLinks(): void
    {
        foreach (['invalid', '018F47A2-7B3C-7DEF-8123-123456789ABC'] as $id) {
            $this->client->request('GET', '/applications/'.$id);
            self::assertResponseStatusCodeSame(400);
            self::assertSelectorTextContains('h1', 'Invalid application identifier.');
        }
        $this->client->request('GET', '/applications/018f47a2-7b3c-7def-8123-000000000000');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Application not found.');
        $document = $this->insertApplication();
        $crawler = $this->client->request('GET', '/applications/'.$document['_id'], ['search' => ['invalid'], 'job' => 'unknown', 'returnUrl' => 'https://example.test']);
        self::assertSame('/applications', $crawler->filter('.back-to-list')->attr('href'));
    }

    private function snapshot(): string
    {
        return serialize($this->collection->find(['_id' => ['$in' => $this->fixtureIds]], ['sort' => ['_id' => 1]])->toArray());
    }
}
