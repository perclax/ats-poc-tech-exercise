<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

#[ODM\Document(collection: 'applications')]
final class ApplicationDocument
{
    public function __construct(
        #[ODM\Id(strategy: 'NONE', type: 'string')]
        public string $id,
        #[ODM\Field(type: 'string')]
        public string $candidateFullName,
        #[ODM\Field(type: 'string')]
        public string $candidateEmail,
        #[ODM\Field(type: 'string', nullable: true)]
        public ?string $candidatePhone,
        #[ODM\Field(type: 'string')]
        public string $jobId,
        #[ODM\Field(type: 'string', nullable: true)]
        public ?string $notes,
        #[ODM\Field(type: 'string')]
        public string $cvText,
        #[ODM\Field(type: 'string')]
        public string $applicationStatus,
        #[ODM\Field(type: 'string')]
        public string $enrichmentStatus,
        #[ODM\Field(type: 'string', nullable: true)]
        public ?string $enrichmentSummary,
        #[ODM\Field(type: 'int', nullable: true)]
        public ?int $enrichmentScore,
        #[ODM\Field(type: 'date_immutable')]
        public \DateTimeImmutable $appliedAt,
        #[ODM\Field(type: 'date_immutable', nullable: true)]
        public ?\DateTimeImmutable $enrichedAt,
    ) {
    }
}
