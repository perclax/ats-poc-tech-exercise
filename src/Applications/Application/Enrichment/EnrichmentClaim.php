<?php

declare(strict_types=1);

namespace App\Applications\Application\Enrichment;

use App\Applications\Domain\Application\Application;

final readonly class EnrichmentClaim
{
    public function __construct(
        public Application $application,
        public EnrichmentAttemptId $attemptId,
        public bool $resumed,
    ) {
    }
}
