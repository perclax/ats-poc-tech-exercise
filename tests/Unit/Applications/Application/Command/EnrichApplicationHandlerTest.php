<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Application\Command;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Command\EnrichApplicationHandler;
use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaim;
use App\Applications\Application\Enrichment\EnrichmentClaimOutcome;
use App\Applications\Application\Enrichment\EnrichmentClaimResult;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Enrichment\RecoveryCandidate;
use App\Applications\Application\Exception\EnrichmentOwnershipLost;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EnrichApplicationHandlerTest extends TestCase
{
    private const string ID = '018f47a2-7b3c-7def-8123-123456789abc';

    public function testPendingClaimIsEnrichedAndCompleted(): void
    {
        $claim = $this->claim(false);
        $repository = new HandlerEnrichmentRepository(EnrichmentClaimResult::acquired($claim));
        $enricher = new RecordingCvEnricher(new EnrichmentResult('Mock analysis: Matched 1 of 1 expected skill groups: PHP.', 100));

        ($this->handler($repository, $enricher))($this->command(), $this->attempt());

        self::assertSame(1, $enricher->calls);
        self::assertSame('Private PHP CV', $enricher->receivedCv);
        self::assertSame(EnrichmentCompletionOutcome::COMPLETED, $repository->completionOutcome);
        self::assertSame(EnrichmentStatus::COMPLETED, $claim->application->enrichmentStatus());
        self::assertSame(100, $claim->application->enrichmentResult()?->score);
        self::assertSame('2026-10-03T10:02:00+00:00', $claim->application->enrichedAt()?->format('c'));
    }

    public function testSameAttemptResumeRunsEnrichment(): void
    {
        $claim = $this->claim(true);
        $repository = new HandlerEnrichmentRepository(EnrichmentClaimResult::acquired($claim));
        $enricher = new RecordingCvEnricher(new EnrichmentResult('Mock analysis: Matched 0 of 1 expected skill groups.', 0));

        ($this->handler($repository, $enricher))($this->command(), $this->attempt());

        self::assertSame(1, $enricher->calls);
        self::assertSame(0, $claim->application->enrichmentResult()?->score);
    }

    #[DataProvider('skippedClaims')]
    public function testUnknownCompetingAndTerminalMessagesAreDeliberatelySkipped(
        EnrichmentClaimOutcome $outcome,
        ?EnrichmentStatus $status,
    ): void {
        $repository = new HandlerEnrichmentRepository(EnrichmentClaimResult::skipped($outcome, $status));
        $enricher = new RecordingCvEnricher(new EnrichmentResult('Unused', 0));

        ($this->handler($repository, $enricher))($this->command(), $this->attempt());

        self::assertSame(0, $enricher->calls);
        self::assertNull($repository->completedClaim);
    }

    /** @return iterable<string, array{EnrichmentClaimOutcome, ?EnrichmentStatus}> */
    public static function skippedClaims(): iterable
    {
        yield 'unknown' => [EnrichmentClaimOutcome::UNKNOWN, null];
        yield 'competing' => [EnrichmentClaimOutcome::COMPETING, EnrichmentStatus::PROCESSING];
        yield 'completed' => [EnrichmentClaimOutcome::COMPLETED, EnrichmentStatus::COMPLETED];
        yield 'failed' => [EnrichmentClaimOutcome::FAILED, EnrichmentStatus::FAILED];
    }

    public function testEnrichmentFailureIsNotSwallowed(): void
    {
        $failure = new \RuntimeException('controlled enrichment failure');
        $repository = new HandlerEnrichmentRepository(EnrichmentClaimResult::acquired($this->claim(false)));
        $enricher = new RecordingCvEnricher(failure: $failure);

        $this->expectExceptionObject($failure);

        ($this->handler($repository, $enricher))($this->command(), $this->attempt());
    }

    public function testUnexpectedProgrammingExceptionIsNotSwallowed(): void
    {
        $failure = new \LogicException('programming defect');
        $repository = new HandlerEnrichmentRepository(EnrichmentClaimResult::acquired($this->claim(false)));
        $enricher = new RecordingCvEnricher(failure: $failure);

        $this->expectExceptionObject($failure);

        ($this->handler($repository, $enricher))($this->command(), $this->attempt());
    }

    public function testOwnershipLossIsVisible(): void
    {
        $repository = new HandlerEnrichmentRepository(
            EnrichmentClaimResult::acquired($this->claim(false)),
            EnrichmentCompletionOutcome::OWNERSHIP_LOST,
        );

        $this->expectException(EnrichmentOwnershipLost::class);

        ($this->handler($repository, new RecordingCvEnricher(new EnrichmentResult('Summary', 50))))($this->command(), $this->attempt());
    }

    private function handler(HandlerEnrichmentRepository $repository, RecordingCvEnricher $enricher): EnrichApplicationHandler
    {
        return new EnrichApplicationHandler(
            $repository,
            new HandlerJobCatalog(),
            $enricher,
            new SequenceClock([
                new \DateTimeImmutable('2026-10-03T10:01:00+00:00'),
                new \DateTimeImmutable('2026-10-03T10:02:00+00:00'),
            ]),
            new NullLogger(),
        );
    }

    private function claim(bool $resumed): EnrichmentClaim
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

        return new EnrichmentClaim($application, $this->attempt(), $resumed);
    }

    private function command(): EnrichApplication
    {
        return new EnrichApplication(ApplicationId::fromString(self::ID));
    }

    private function attempt(): EnrichmentAttemptId
    {
        return new EnrichmentAttemptId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    }
}

final class HandlerEnrichmentRepository implements ApplicationEnrichmentRepository
{
    public ?EnrichmentClaim $completedClaim = null;
    public ?EnrichmentCompletionOutcome $completionOutcome = null;

    public function __construct(
        private readonly EnrichmentClaimResult $claimResult,
        private readonly EnrichmentCompletionOutcome $configuredCompletion = EnrichmentCompletionOutcome::COMPLETED,
    ) {
    }

    public function claim(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): EnrichmentClaimResult
    {
        return $this->claimResult;
    }

    public function complete(EnrichmentClaim $claim): EnrichmentCompletionOutcome
    {
        $this->completedClaim = $claim;
        $this->completionOutcome = $this->configuredCompletion;

        return $this->configuredCompletion;
    }

    public function returnOwnedClaimToPending(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        return false;
    }

    public function markOwnedClaimFailed(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        return false;
    }

    public function recoveryCandidates(\DateTimeImmutable $staleBefore, int $limit): array
    {
        return [];
    }

    public function resetStaleProcessing(RecoveryCandidate $candidate, \DateTimeImmutable $staleBefore): bool
    {
        return false;
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
