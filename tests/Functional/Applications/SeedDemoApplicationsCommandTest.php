<?php

declare(strict_types=1);

namespace App\Tests\Functional\Applications;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use App\Applications\Presentation\Console\SeedDemoApplicationsCommand;
use App\Tests\Support\MongoDbTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SeedDemoApplicationsCommandTest extends MongoDbTestCase
{
    public function testSeedsProcessedDemosOnceWithoutDispatchingMessages(): void
    {
        $transport = self::getContainer()->get('messenger.transport.enrichment_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Created 3 demo applications.', $tester->getDisplay());

        $perfect = $this->application('018f47a2-7b3c-7def-8123-000000000001');
        $zero = $this->application('018f47a2-7b3c-7def-8123-000000000002');
        $failed = $this->application('018f47a2-7b3c-7def-8123-000000000003');
        self::assertSame(100, $perfect->enrichmentResult()?->score);
        self::assertSame($perfect->candidate()->email->value, $zero->candidate()->email->value);
        self::assertSame(EnrichmentStatus::COMPLETED, $zero->enrichmentStatus());
        self::assertSame(0, $zero->enrichmentResult()?->score);
        self::assertSame(EnrichmentStatus::FAILED, $failed->enrichmentStatus());
        self::assertSame([], $transport->getSent());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('skipped', $tester->getDisplay());
        self::assertSame(3, $this->collection()->countDocuments());
    }

    public function testDoesNotSeedWhenApplicationsAlreadyExist(): void
    {
        $this->repository()->save(new Application(
            ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc'),
            new Candidate('Fictional Candidate', new EmailAddress('fictional@example.test'), null),
            new JobId('backend-developer'),
            null,
            'Fictional PHP demonstration.',
            new \DateTimeImmutable('2026-10-01T10:03:00Z'),
        ));

        self::assertSame(Command::SUCCESS, $this->commandTester()->execute([]));
        self::assertSame(1, $this->collection()->countDocuments());
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

    private function application(string $id): Application
    {
        $application = $this->repository()->find(ApplicationId::fromString($id));
        self::assertNotNull($application);

        return $application;
    }

    private function collection(): \MongoDB\Collection
    {
        return $this->documentManager->getDocumentCollection(ApplicationDocument::class);
    }
}
