<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;

final readonly class ApplicationSearchCriteria
{
    public function __construct(
        public ?string $search = null,
        public ?JobId $jobId = null,
        public ?ApplicationStatus $applicationStatus = null,
        public ?EnrichmentStatus $enrichmentStatus = null,
    ) {
    }
}
