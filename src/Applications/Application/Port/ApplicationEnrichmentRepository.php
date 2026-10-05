<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaim;
use App\Applications\Application\Enrichment\EnrichmentClaimResult;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Enrichment\RecoveryCandidate;
use App\Applications\Domain\Application\ApplicationId;

interface ApplicationEnrichmentRepository
{
    public function claim(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): EnrichmentClaimResult;

    public function complete(EnrichmentClaim $claim): EnrichmentCompletionOutcome;

    public function returnOwnedClaimToPending(ApplicationId $id, EnrichmentAttemptId $attemptId): bool;

    public function markOwnedClaimFailed(ApplicationId $id, EnrichmentAttemptId $attemptId): bool;

    /** @return list<RecoveryCandidate> */
    public function recoveryCandidates(\DateTimeImmutable $staleBefore, int $limit): array;

    public function resetStaleProcessing(RecoveryCandidate $candidate, \DateTimeImmutable $staleBefore): bool;
}
