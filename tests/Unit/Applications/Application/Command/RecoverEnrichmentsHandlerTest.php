<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Application\Command;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Command\RecoverEnrichments;
use App\Applications\Application\Command\RecoverEnrichmentsHandler;
use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaim;
use App\Applications\Application\Enrichment\EnrichmentClaimResult;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Enrichment\RecoveryCandidate;
use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\EnrichmentDispatcher;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RecoverEnrichmentsHandlerTest extends TestCase
{
    public function testItResetsStaleAndDispatchesPendingWithSharedLimit(): void
    {
        $stale = $this->candidate('018f47a2-7b3c-7def-8123-123456789aba', EnrichmentStatus::PROCESSING);
        $pending = $this->candidate('018f47a2-7b3c-7def-8123-123456789abb', EnrichmentStatus::PENDING);
        $repository = new RecoveryRepository([$stale, $pending]);
        $dispatcher = new RecoveryDispatcher();

        $report = ($this->handler($repository, $dispatcher))(new RecoverEnrichments(900, 2));

        self::assertSame(2, $repository->requestedLimit);
        self::assertSame('2026-10-03T09:45:00+00:00', $repository->staleBefore?->format('c'));
        self::assertSame([$stale], $repository->resetCandidates);
        self::assertSame([$stale->applicationId->value, $pending->applicationId->value], $dispatcher->applicationIds);
        self::assertSame(2, $report->eligible);
        self::assertSame(1, $report->reset);
        self::assertSame(2, $report->dispatched);
        self::assertTrue($report->succeeded());
    }

    public function testDryRunDoesNotResetOrDispatch(): void
    {
        $repository = new RecoveryRepository([$this->candidate('018f47a2-7b3c-7def-8123-123456789aba', EnrichmentStatus::PROCESSING)]);
        $dispatcher = new RecoveryDispatcher();

        $report = ($this->handler($repository, $dispatcher))(new RecoverEnrichments(dryRun: true));

        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->eligible);
        self::assertSame([], $repository->resetCandidates);
        self::assertSame([], $dispatcher->applicationIds);
    }

    public function testChangedStaleCandidateIsSkippedAndDispatchFailureIsReported(): void
    {
        $stale = $this->candidate('018f47a2-7b3c-7def-8123-123456789aba', EnrichmentStatus::PROCESSING);
        $pending = $this->candidate('018f47a2-7b3c-7def-8123-123456789abb', EnrichmentStatus::PENDING);
        $repository = new RecoveryRepository([$stale, $pending], resetSucceeds: false);
        $dispatcher = new RecoveryDispatcher($pending->applicationId);

        $report = ($this->handler($repository, $dispatcher))(new RecoverEnrichments());

        self::assertSame(1, $report->skipped);
        self::assertSame(1, $report->dispatchFailures);
        self::assertFalse($report->succeeded());
    }

    private function handler(RecoveryRepository $repository, RecoveryDispatcher $dispatcher): RecoverEnrichmentsHandler
    {
        return new RecoverEnrichmentsHandler(
            $repository,
            $dispatcher,
            new RecoveryClock(),
            new NullLogger(),
        );
    }

    private function candidate(string $id, EnrichmentStatus $status): RecoveryCandidate
    {
        return new RecoveryCandidate(
            ApplicationId::fromString($id),
            $status,
            EnrichmentStatus::PROCESSING === $status ? new \DateTimeImmutable('2026-10-03T09:00:00+00:00') : null,
            EnrichmentStatus::PROCESSING === $status ? new EnrichmentAttemptId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') : null,
        );
    }
}

final class RecoveryRepository implements ApplicationEnrichmentRepository
{
    public ?int $requestedLimit = null;
    public ?\DateTimeImmutable $staleBefore = null;
    /** @var list<RecoveryCandidate> */
    public array $resetCandidates = [];

    /** @param list<RecoveryCandidate> $candidates */
    public function __construct(private readonly array $candidates, private readonly bool $resetSucceeds = true)
    {
    }

    public function claim(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): EnrichmentClaimResult
    {
        throw new \LogicException('Not used.');
    }

    public function complete(EnrichmentClaim $claim): EnrichmentCompletionOutcome
    {
        throw new \LogicException('Not used.');
    }

    public function returnOwnedClaimToPending(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        throw new \LogicException('Not used.');
    }

    public function markOwnedClaimFailed(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        throw new \LogicException('Not used.');
    }

    public function recoveryCandidates(\DateTimeImmutable $staleBefore, int $limit): array
    {
        $this->staleBefore = $staleBefore;
        $this->requestedLimit = $limit;

        return \array_slice($this->candidates, 0, $limit);
    }

    public function resetStaleProcessing(RecoveryCandidate $candidate, \DateTimeImmutable $staleBefore): bool
    {
        $this->resetCandidates[] = $candidate;

        return $this->resetSucceeds;
    }
}

final class RecoveryDispatcher implements EnrichmentDispatcher
{
    /** @var list<string> */
    public array $applicationIds = [];

    public function __construct(private readonly ?ApplicationId $failingId = null)
    {
    }

    public function dispatch(EnrichApplication $command): void
    {
        $this->applicationIds[] = $command->applicationId->value;
        if ($command->applicationId->value === $this->failingId?->value) {
            throw new EnrichmentDispatchFailed('controlled transport failure');
        }
    }
}

final class RecoveryClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-03T10:00:00+00:00');
    }
}
