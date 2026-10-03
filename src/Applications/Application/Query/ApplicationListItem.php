<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;

final readonly class ApplicationListItem
{
    public function __construct(
        public string $id,
        public string $candidateName,
        public string $candidateEmail,
        public string $jobId,
        public string $jobTitle,
        public ApplicationStatus $applicationStatus,
        public EnrichmentStatus $enrichmentStatus,
        public ?int $score,
        public \DateTimeImmutable $appliedAt,
    ) {
    }
}
