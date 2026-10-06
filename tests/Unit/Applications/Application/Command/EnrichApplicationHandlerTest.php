<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Application\Command;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Command\EnrichApplicationHandler;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\CvEnricher;
use App\Applications\Application\Port\JobCatalog;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;
use App\Applications\Domain\Job\SkillGroup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EnrichApplicationHandlerTest extends TestCase
{
    private const string ID = '018f47a2-7b3c-7def-8123-123456789abc';

    public function testClaimedApplicationIsEnrichedAndCompleted(): void
    {
        $repository = new ClaimingApplicationRepository($this->claimedApplication());
        $enricher = new RecordingCvEnricher(new EnrichmentResult('Mock analysis: Matched 1 of 1 expected skill groups: PHP.', 100));

        ($this->handler($repository, $enricher))($this->command());

        self::assertSame(1, $enricher->calls);
        self::assertSame('Private PHP CV', $enricher->receivedCv);
        $completed = $repository->completed;
        self::assertNotNull($completed);
        self::assertSame(EnrichmentStatus::COMPLETED, $completed->enrichmentStatus());
        self::assertSame(100, $completed->enrichmentResult()?->score);
        self::assertSame('2026-10-03T10:02:00+00:00', $completed->enrichedAt()?->format('c'));
    }

    public function testApplicationThatCannotBeClaimedIsSkipped(): void
    {
        $repository = new ClaimingApplicationRepository(null);
        $enricher = new RecordingCvEnricher(new EnrichmentResult('Unused', 0));

        ($this->handler($repository, $enricher))($this->command());

        self::assertSame(0, $enricher->calls);
        self::assertNull($repository->completed);
    }

    public function testEnrichmentFailureIsNotSwallowed(): void
    {
        $failure = new \RuntimeException('controlled enrichment failure');
        $repository = new ClaimingApplicationRepository($this->claimedApplication());

        $this->expectExceptionObject($failure);

        ($this->handler($repository, new RecordingCvEnricher(failure: $failure)))($this->command());
    }

    private function handler(ClaimingApplicationRepository $repository, RecordingCvEnricher $enricher): EnrichApplicationHandler
    {
        return new EnrichApplicationHandler(
            $repository,
            new HandlerJobCatalog(),
            $enricher,
            new SequenceClock([new \DateTimeImmutable('2026-10-03T10:02:00+00:00')]),
            new NullLogger(),
        );
    }

    private function claimedApplication(): Application
    {
        $application = new Application(
            ApplicationId::fromString(self::ID),
            new Candidate('Private Candidate', new EmailAddress('private@example.test'), null),
            new JobId('backend-developer'),
            'Private notes',
            'Private PHP CV',
            new \DateTimeImmutable('2026-10-03T10:00:00+00:00'),
        );
        $application->startEnrichment();

        return $application;
    }

    private function command(): EnrichApplication
    {
        return new EnrichApplication(ApplicationId::fromString(self::ID));
    }
}

final class ClaimingApplicationRepository implements ApplicationRepository
{
    public ?Application $completed = null;

    public function __construct(private readonly ?Application $claimable)
    {
    }

    public function find(ApplicationId $id): ?Application
    {
        return null;
    }

    public function save(Application $application): void
    {
    }

    public function claimForEnrichment(ApplicationId $id): ?Application
    {
        return $this->claimable;
    }

    public function completeEnrichment(Application $application): void
    {
        $this->completed = $application;
    }

    public function releaseEnrichment(ApplicationId $id): void
    {
    }

    public function failEnrichment(ApplicationId $id): void
    {
    }
}

final class RecordingCvEnricher implements CvEnricher
{
    public int $calls = 0;
    public ?string $receivedCv = null;

    public function __construct(
        private readonly ?EnrichmentResult $result = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function enrich(string $cvText, Job $job): EnrichmentResult
    {
        ++$this->calls;
        $this->receivedCv = $cvText;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return $this->result ?? throw new \LogicException('A controlled result is required.');
    }
}

final class HandlerJobCatalog implements JobCatalog
{
    public function all(): array
    {
        return [new Job(new JobId('backend-developer'), 'Backend Developer', 'Backend role', [new SkillGroup('PHP', ['php'])])];
    }

    public function find(JobId $id): ?Job
    {
        return 'backend-developer' === $id->value ? $this->all()[0] : null;
    }
}

final class SequenceClock implements Clock
{
    /** @param list<\DateTimeImmutable> $times */
    public function __construct(private array $times)
    {
    }

    public function now(): \DateTimeImmutable
    {
        if ([] === $this->times) {
            throw new \LogicException('No controlled time remains.');
        }

        return array_shift($this->times);
    }
}
