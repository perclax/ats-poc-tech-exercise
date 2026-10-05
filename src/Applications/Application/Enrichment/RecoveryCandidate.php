<?php

declare(strict_types=1);

namespace App\Applications\Application\Enrichment;

use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Enrichment\EnrichmentStatus;

final readonly class RecoveryCandidate
{
    public function __construct(
        public ApplicationId $applicationId,
        public EnrichmentStatus $status,
        public ?\DateTimeImmutable $processingStartedAt,
        public ?EnrichmentAttemptId $attemptId,
    ) {
        if (!\in_array($status, [EnrichmentStatus::PENDING, EnrichmentStatus::PROCESSING], true)) {
            throw new \InvalidArgumentException('Only pending or processing applications can be recovery candidates.');
        }
    }
}
