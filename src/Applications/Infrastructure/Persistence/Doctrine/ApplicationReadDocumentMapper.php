<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use App\Applications\Application\Query\ApplicationDetail;
use App\Applications\Application\Query\ApplicationListItem;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use MongoDB\BSON\UTCDateTime;

final class ApplicationReadDocumentMapper
{
    /**
     * @param array<string, mixed>                                     $document
     * @param array<string, array{title: string, description: string}> $jobs
     */
    public function toListItem(array $document, array $jobs): ApplicationListItem
    {
        $jobId = $this->string($document, 'jobId');

        return new ApplicationListItem(
            ApplicationId::fromString($this->string($document, '_id'))->value,
            $this->string($document, 'candidateFullName'),
            $this->string($document, 'candidateEmail'),
            $jobId,
            $jobs[$jobId]['title'] ?? 'Unavailable job ('.$jobId.')',
            ApplicationStatus::from($this->string($document, 'applicationStatus')),
            EnrichmentStatus::from($this->string($document, 'enrichmentStatus')),
            $this->score($document),
            $this->date($document, 'appliedAt'),
        );
    }

    /**
     * @param array<string, mixed>                                     $document
     * @param array<string, array{title: string, description: string}> $jobs
     */
    public function toDetail(array $document, array $jobs): ApplicationDetail
    {
        $item = $this->toListItem($document, $jobs);

        return new ApplicationDetail(
            $item->id,
            $item->candidateName,
            $item->candidateEmail,
            $this->nullableString($document, 'candidatePhone'),
            $item->jobId,
            $item->jobTitle,
            $jobs[$item->jobId]['description'] ?? 'This job is no longer available in the catalogue.',
            $this->nullableString($document, 'notes'),
            $this->string($document, 'cvText'),
            $item->applicationStatus,
            $item->enrichmentStatus,
            $this->nullableString($document, 'enrichmentSummary'),
            $item->score,
            $item->appliedAt,
            null === ($document['enrichedAt'] ?? null) ? null : $this->date($document, 'enrichedAt'),
        );
    }

    /** @param array<string, mixed> $document */
    private function string(array $document, string $field): string
    {
        $value = $document[$field] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('A required application read field is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    private function nullableString(array $document, string $field): ?string
    {
        return null === ($document[$field] ?? null) ? null : $this->string($document, $field);
    }

    /** @param array<string, mixed> $document */
    private function score(array $document): ?int
    {
        $score = $document['enrichmentScore'] ?? null;
        if (null !== $score && (!\is_int($score) || $score < 0 || $score > 100)) {
            throw new \UnexpectedValueException('The stored application score is invalid.');
        }

        return $score;
    }

    /** @param array<string, mixed> $document */
    private function date(array $document, string $field): \DateTimeImmutable
    {
        $value = $document[$field] ?? null;
        if (!$value instanceof UTCDateTime) {
            throw new \UnexpectedValueException('A stored application timestamp is invalid.');
        }

        return $value->toDateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'));
    }
}
