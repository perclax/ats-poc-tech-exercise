<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Persistence;

use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocumentMapper;
use PHPUnit\Framework\TestCase;

final class ApplicationDocumentMapperTest extends TestCase
{
    public function testItPreservesCompletedEnrichmentIncludingAZeroScore(): void
    {
        $application = new Application(
            ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc'),
            new Candidate('Ada Lovelace', new EmailAddress('ada@example.test'), '+44 123'),
            new JobId('backend-developer'),
            'Notes',
            'PHP',
            new \DateTimeImmutable('2026-10-03T10:20:30+00:00'),
        );
        $application->startEnrichment();
        $application->completeEnrichment(
            new EnrichmentResult('No expected skills detected.', 0),
            new \DateTimeImmutable('2026-10-03T10:21:30+00:00'),
        );
        $mapper = new ApplicationDocumentMapper();

        $document = $mapper->toDocument($application);
        $restored = $mapper->toDomain($document);

        self::assertSame('completed', $document->enrichmentStatus);
        self::assertSame(0, $document->enrichmentScore);
        self::assertSame(EnrichmentStatus::COMPLETED, $restored->enrichmentStatus());
        $result = $restored->enrichmentResult();
        self::assertNotNull($result);
        self::assertSame(0, $result->score);
        self::assertSame('No expected skills detected.', $result->summary);
        self::assertSame('2026-10-03T10:21:30+00:00', $restored->enrichedAt()?->format('c'));
    }
}
