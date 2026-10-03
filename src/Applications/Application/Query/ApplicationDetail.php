<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;

final readonly class ApplicationDetail
{
    public function __construct(
        public string $id,
        public string $candidateName,
        public string $candidateEmail,
        public ?string $candidatePhone,
        public string $jobId,
        public string $jobTitle,
        public string $jobDescription,
        public ?string $notes,
        public string $cvText,
        public ApplicationStatus $applicationStatus,
        public EnrichmentStatus $enrichmentStatus,
        public ?string $summary,
        public ?int $score,
        public \DateTimeImmutable $appliedAt,
        public ?\DateTimeImmutable $enrichedAt,
    ) {
    }
}
