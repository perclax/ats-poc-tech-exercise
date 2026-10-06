<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Persistence;

use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationReadDocumentMapper;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationReadDocumentMapperTest extends TestCase
{
    public function testItMapsZeroAndUtcDates(): void
    {
        $mapper = new ApplicationReadDocumentMapper();
        $document = $this->document() + ['enrichmentScore' => 0];
        $detail = $mapper->toDetail($document, ['backend-developer' => ['title' => 'Backend Developer', 'description' => 'Catalogue description.']]);
        self::assertSame(0, $detail->score);
        self::assertSame('2026-10-05T10:00:00+00:00', $detail->appliedAt->format('c'));
        self::assertNull($detail->candidatePhone);
        self::assertNull($detail->notes);
        self::assertNull($detail->summary);
        self::assertNull($detail->enrichedAt);
        self::assertSame('Backend Developer', $detail->jobTitle);
        self::assertSame('Catalogue description.', $detail->jobDescription);
    }

    public function testMissingOptionalFieldsAndMissingCatalogueEntriesAreSupported(): void
    {
        $detail = (new ApplicationReadDocumentMapper())->toDetail($this->document(), []);
        self::assertNull($detail->score);
        self::assertSame('Unavailable job (backend-developer)', $detail->jobTitle);
        self::assertSame('This job is no longer available in the catalogue.', $detail->jobDescription);
    }

    #[DataProvider('invalidFields')]
    public function testMalformedStoredFieldsFailWithoutDisclosingCandidateData(string $field, mixed $value): void
    {
        try {
            (new ApplicationReadDocumentMapper())->toDetail(array_replace($this->document(), [$field => $value]), []);
            self::fail('Malformed data must be rejected.');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringNotContainsString('candidate@example.test', $exception->getMessage());
            self::assertStringNotContainsString('Private CV', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFields(): iterable
    {
        yield 'required string' => ['candidateFullName', ['invalid']];
        yield 'timestamp' => ['appliedAt', 'invalid'];
        yield 'score type' => ['enrichmentScore', '0'];
        yield 'score range' => ['enrichmentScore', 101];
        yield 'optional string' => ['notes', ['invalid']];
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            '_id' => '018f47a2-7b3c-7def-8123-123456789abc',
            'candidateFullName' => 'Fictional Candidate', 'candidateEmail' => 'candidate@example.test',
            'jobId' => 'backend-developer', 'cvText' => 'Private CV',
            'applicationStatus' => 'received', 'enrichmentStatus' => 'pending',
            'appliedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-05T12:00:00+02:00')),
        ];
    }
}
