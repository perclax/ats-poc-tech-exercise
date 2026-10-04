<?php

declare(strict_types=1);

namespace App\Applications\Application\Enrichment;

use App\Applications\Domain\Enrichment\EnrichmentStatus;

final readonly class EnrichmentClaimResult
{
    private function __construct(
        public EnrichmentClaimOutcome $outcome,
        public ?EnrichmentClaim $claim = null,
        public ?EnrichmentStatus $currentStatus = null,
    ) {
    }

    public static function acquired(EnrichmentClaim $claim): self
    {
        return new self($claim->resumed ? EnrichmentClaimOutcome::RESUMED : EnrichmentClaimOutcome::CLAIMED, $claim, EnrichmentStatus::PROCESSING);
    }

    public static function skipped(EnrichmentClaimOutcome $outcome, ?EnrichmentStatus $status): self
    {
        if (\in_array($outcome, [EnrichmentClaimOutcome::CLAIMED, EnrichmentClaimOutcome::RESUMED], true)) {
            throw new \InvalidArgumentException('An acquired claim must contain the claimed application.');
        }

        return new self($outcome, null, $status);
    }
}
