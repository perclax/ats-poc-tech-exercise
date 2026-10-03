<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;

final class ApplicationDocumentMapper
{
    public function toDocument(Application $application): ApplicationDocument
    {
        return new ApplicationDocument(
            $application->id()->value,
            $application->candidate()->fullName,
            $application->candidate()->email->value,
            $application->candidate()->phone,
            $application->jobId()->value,
            $application->notes(),
            $application->cvText(),
            $application->status()->value,
            $application->enrichmentStatus()->value,
            $application->enrichmentResult()?->summary,
            $application->enrichmentResult()?->score,
            $application->appliedAt(),
            $application->enrichedAt(),
        );
    }

    public function toDomain(ApplicationDocument $document): Application
    {
        if (ApplicationStatus::RECEIVED->value !== $document->applicationStatus) {
            throw new \UnexpectedValueException('The stored application status is not supported.');
        }

        $application = new Application(
            ApplicationId::fromString($document->id),
            new Candidate(
                $document->candidateFullName,
                new EmailAddress($document->candidateEmail),
                $document->candidatePhone,
            ),
            new JobId($document->jobId),
            $document->notes,
            $document->cvText,
            $document->appliedAt,
        );

        $enrichmentStatus = EnrichmentStatus::from($document->enrichmentStatus);
        if (EnrichmentStatus::PENDING === $enrichmentStatus) {
            $this->assertNoEnrichmentResult($document);

            return $application;
        }

        $application->startEnrichment();
        if (EnrichmentStatus::PROCESSING === $enrichmentStatus) {
            $this->assertNoEnrichmentResult($document);

            return $application;
        }

        if (EnrichmentStatus::FAILED === $enrichmentStatus) {
            $this->assertNoEnrichmentResult($document);
            $application->failEnrichment();

            return $application;
        }

        if (null === $document->enrichmentSummary || null === $document->enrichmentScore || null === $document->enrichedAt) {
            throw new \UnexpectedValueException('A completed application must contain complete enrichment data.');
        }

        $application->completeEnrichment(
            new EnrichmentResult($document->enrichmentSummary, $document->enrichmentScore),
            $document->enrichedAt,
        );

        return $application;
    }

    private function assertNoEnrichmentResult(ApplicationDocument $document): void
    {
        if (null !== $document->enrichmentSummary || null !== $document->enrichmentScore || null !== $document->enrichedAt) {
            throw new \UnexpectedValueException('An unfinished application cannot contain enrichment result data.');
        }
    }
}
