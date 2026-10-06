<?php

declare(strict_types=1);

namespace App\Tests\Functional\Applications;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\EventPublisher;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use App\Applications\Presentation\Console\SeedDemoApplicationsCommand;
use App\Tests\Support\MongoDbTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SeedDemoApplicationsCommandTest extends MongoDbTestCase
{
    private const IDS = [
        '018f47a2-7b3c-7def-8123-000000000001',
        '018f47a2-7b3c-7def-8123-000000000002',
        '018f47a2-7b3c-7def-8123-000000000003',
        '018f47a2-7b3c-7def-8123-000000000004',
    ];

    public function testSeedsExactDemoRecordsAndSecondExecutionIsIdempotent(): void
    {
        $publisher = $this->createMock(EventPublisher::class);
        $publisher->expects(self::never())->method('publish');
        self::getContainer()->set(EventPublisher::class, $publisher);
        $this->resetTransports();

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Created: 4; already present: 0', $tester->getDisplay());

        $repository = $this->repository();
        $first = $this->application($repository, self::IDS[0]);
        $second = $this->application($repository, self::IDS[1]);
        $pending = $this->application($repository, self::IDS[2]);
        $failed = $this->application($repository, self::IDS[3]);

        self::assertSame('demo.avery@example.test', $first->candidate()->email->value);
        self::assertSame($first->candidate()->email->value, $second->candidate()->email->value);
        self::assertSame('<strong>Fictional demo note</strong>', $first->notes());
        self::assertNotNull($first->enrichmentResult());
        self::assertSame(100, $first->enrichmentResult()->score);
        self::assertSame('Mock analysis: Matched 4 of 4 expected skill groups: PHP, Symfony, Databases, REST APIs.', $first->enrichmentResult()->summary);
        self::assertNotNull($second->enrichmentResult());
        self::assertSame(0, $second->enrichmentResult()->score);
        self::assertSame('Mock analysis: Matched 0 of 4 expected skill groups.', $second->enrichmentResult()->summary);
        self::assertSame(EnrichmentStatus::PENDING, $pending->enrichmentStatus());
        self::assertNull($pending->enrichmentResult());
        self::assertNull($pending->enrichedAt());
        self::assertSame(EnrichmentStatus::FAILED, $failed->enrichmentStatus());
        self::assertNull($failed->enrichmentResult());
        self::assertNull($failed->enrichedAt());

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $browser = new KernelBrowser($kernel);
        $browser->request('GET', '/applications/'.self::IDS[0]);
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('&lt;strong&gt;Fictional demo note&lt;/strong&gt;', $html);
        self::assertStringNotContainsString('<strong>Fictional demo note</strong>', $html);

        $secondRun = $this->commandTester();
        self::assertSame(Command::SUCCESS, $secondRun->execute([]));
        self::assertStringContainsString('Created: 0; already present: 4', $secondRun->getDisplay());
        $this->assertTransportsEmpty();
    }

    public function testPartialDatasetCreatesOnlyMissingRecords(): void
    {
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $this->collection()->deleteOne(['_id' => self::IDS[2]]);
        $this->documentManager->clear();

        $partialRun = $this->commandTester();
        self::assertSame(Command::SUCCESS, $partialRun->execute([]));
        self::assertStringContainsString('Created: 1; already present: 3', $partialRun->getDisplay());
        self::assertCount(4, $this->collection()->find(['_id' => ['$in' => self::IDS]])->toArray());
    }

    public function testConflictIsDetectedBeforeAnyWriteAndPreserved(): void
    {
        $conflict = new Application(
            ApplicationId::fromString(self::IDS[0]),
            new Candidate('Conflicting Candidate', new EmailAddress('conflict@example.test'), null),
            new JobId('backend-developer'),
            null,
            'PHP',
            new \DateTimeImmutable('2026-10-01T10:03:00Z'),
        );
        $this->repository()->save($conflict);

        $tester = $this->commandTester();
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No records created', $tester->getDisplay());
        self::assertCount(1, $this->collection()->find(['_id' => ['$in' => self::IDS]])->toArray());
        self::assertSame('Conflicting Candidate', $this->application($this->repository(), self::IDS[0])->candidate()->fullName);
    }

    public function testProgressedPendingDemoIsAcceptedAndNeverReset(): void
    {
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $this->collection()->updateOne(['_id' => self::IDS[2]], ['$set' => [
            'enrichmentStatus' => 'completed',
            'enrichmentSummary' => 'Mock analysis: Matched 2 of 8 expected skill groups: PHP, React.',
            'enrichmentScore' => 25,
            'enrichedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable('2026-10-01T10:01:30Z')),
        ]]);
        $this->documentManager->clear();

        $progressedRun = $this->commandTester();
        self::assertSame(Command::SUCCESS, $progressedRun->execute([]));
        self::assertStringContainsString('Created: 0; already present: 4', $progressedRun->getDisplay());
        $progressed = $this->application($this->repository(), self::IDS[2]);
        self::assertSame(EnrichmentStatus::COMPLETED, $progressed->enrichmentStatus());
        self::assertEquals(new EnrichmentResult('Mock analysis: Matched 2 of 8 expected skill groups: PHP, React.', 25), $progressed->enrichmentResult());
    }

    public function testConditionalSeedCreatesFourThenSkipsWithoutMessages(): void
    {
        $publisher = $this->createMock(EventPublisher::class);
        $publisher->expects(self::never())->method('publish');
        self::getContainer()->set(EventPublisher::class, $publisher);
        $this->resetTransports();
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--if-empty' => true]));
        self::assertStringContainsString('Created: 4', $tester->getDisplay());
        self::assertSame(4, $this->collection()->countDocuments());
        $before = $this->collection()->find()->toArray();
        self::assertSame(Command::SUCCESS, $tester->execute(['--if-empty' => true]));
        self::assertStringContainsString('skipped', $tester->getDisplay());
        self::assertEquals($before, $this->collection()->find()->toArray());
        $this->assertTransportsEmpty();
    }

    public function testConditionalCommandDelegatesWithoutLoadingOrValidatingReservedRecords(): void
    {
        $this->collection()->insertOne(['_id' => self::IDS[0], 'fictionalConflict' => true]);
        $repository = $this->createMock(ApplicationRepository::class);
        $repository->expects(self::never())->method('find');
        $repository->expects(self::never())->method('save');
        self::getContainer()->set(ApplicationRepository::class, $repository);
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--if-empty' => true]));
        self::assertStringContainsString('skipped', $tester->getDisplay());
        self::assertSame(1, $this->collection()->countDocuments());
        $constructor = (new \ReflectionClass(SeedDemoApplicationsCommand::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters());
        self::assertSame(\App\Applications\Infrastructure\Demo\DemoApplicationSeeder::class, (string) $constructor->getParameters()[0]->getType());
    }

    public function testConditionalSeedSkipsAnOrdinaryApplication(): void
    {
        $this->repository()->save(new Application(
            ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc'),
            new Candidate('Fictional Ordinary Candidate', new EmailAddress('ordinary@example.test'), null),
            new JobId('backend-developer'),
            null,
            'Fictional PHP demonstration.',
            new \DateTimeImmutable('2026-10-01T10:03:00Z'),
        ));
        $before = $this->collection()->find()->toArray();
        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--if-empty' => true]));
        self::assertEquals($before, $this->collection()->find()->toArray());
        self::assertSame(0, $this->collection()->countDocuments(['_id' => ['$in' => self::IDS]]));
    }

    public function testConditionalSeedPreservesEveryProgressedState(): void
    {
        foreach (['processing', 'completed', 'failed'] as $status) {
            $this->cleanApplications();
            self::assertSame(Command::SUCCESS, $this->commandTester()->execute([]));
            $fields = ['enrichmentStatus' => $status];
            if ('completed' === $status) {
                $fields += [
                    'enrichmentSummary' => 'Mock analysis: Matched 2 of 8 expected skill groups: PHP, React.',
                    'enrichmentScore' => 25,
                    'enrichedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable('2026-10-01T10:01:30Z')),
                ];
            }
            $this->collection()->updateOne(['_id' => self::IDS[2]], ['$set' => $fields]);
            $before = $this->collection()->find()->toArray();
            $tester = $this->commandTester();
            self::assertSame(Command::SUCCESS, $tester->execute(['--if-empty' => true]));
            self::assertStringContainsString('skipped', $tester->getDisplay());
            self::assertEquals($before, $this->collection()->find()->toArray());
        }
    }

    private function commandTester(): CommandTester
    {
        $command = self::getContainer()->get(SeedDemoApplicationsCommand::class);
        self::assertInstanceOf(SeedDemoApplicationsCommand::class, $command);

        return new CommandTester($command);
    }

    private function repository(): ApplicationRepository
    {
        $repository = self::getContainer()->get(ApplicationRepository::class);
        self::assertInstanceOf(ApplicationRepository::class, $repository);

        return $repository;
    }

    private function application(ApplicationRepository $repository, string $id): Application
    {
        $application = $repository->find(ApplicationId::fromString($id));
        self::assertNotNull($application);

        return $application;
    }

    private function collection(): \MongoDB\Collection
    {
        return $this->documentManager->getDocumentCollection(ApplicationDocument::class);
    }

    private function resetTransports(): void
    {
        foreach (['enrichment_async', 'failed', 'infrastructure_async'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            $transport->reset();
        }
    }

    private function assertTransportsEmpty(): void
    {
        foreach (['enrichment_async', 'failed', 'infrastructure_async'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            self::assertSame([], $transport->getSent());
        }
    }
}
