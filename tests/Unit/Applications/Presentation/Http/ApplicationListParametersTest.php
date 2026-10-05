<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Presentation\Http;

use App\Applications\Application\Query\JobView;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Presentation\Http\ApplicationListParameters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationListParametersTest extends TestCase
{
    public function testItNormalizesSupportedValuesAndDropsUntrustedLinkParameters(): void
    {
        $parameters = ApplicationListParameters::fromArray([
            'search' => '  Álvaro .* \\  ',
            'job' => ' backend-developer ',
            'applicationStatus' => ' received ',
            'enrichmentStatus' => ' completed ',
            'returnUrl' => 'https://example.test',
        ], $this->jobs());
        self::assertSame([], $parameters->errors);
        self::assertSame('Álvaro .* \\', $parameters->criteria->search);
        self::assertSame('backend-developer', $parameters->criteria->jobId?->value);
        self::assertSame(ApplicationStatus::RECEIVED, $parameters->criteria->applicationStatus);
        self::assertSame(EnrichmentStatus::COMPLETED, $parameters->criteria->enrichmentStatus);
        self::assertSame(['search', 'job', 'applicationStatus', 'enrichmentStatus'], array_keys($parameters->linkParameters()));
    }

    public function testBlankValuesProduceUnfilteredCriteria(): void
    {
        $parameters = ApplicationListParameters::fromArray(['search' => '  '], $this->jobs());
        self::assertSame([], $parameters->errors);
        self::assertNull($parameters->criteria->search);
        self::assertNull($parameters->criteria->jobId);
        self::assertSame([], $parameters->linkParameters());
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejectedWithoutIncludingThemInLinks(string $field, mixed $value): void
    {
        $parameters = ApplicationListParameters::fromArray([$field => $value], $this->jobs());
        self::assertArrayHasKey($field, $parameters->errors);
        self::assertArrayNotHasKey($field, $parameters->linkParameters());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidValues(): iterable
    {
        foreach (['search', 'job', 'applicationStatus', 'enrichmentStatus'] as $field) {
            yield $field.' array' => [$field, ['value']];
            yield $field.' invalid UTF-8' => [$field, "\xFF"];
            yield $field.' null' => [$field, null];
        }
        yield 'long search' => ['search', str_repeat('á', 255)];
        yield 'unknown job' => ['job', 'retired-job'];
        yield 'invalid application status' => ['applicationStatus', 'hired'];
        yield 'invalid enrichment status' => ['enrichmentStatus', 'COMPLETED'];
    }

    public function testTheSearchLimitCountsUnicodeCharacters(): void
    {
        $parameters = ApplicationListParameters::fromArray(['search' => str_repeat('á', 254)], $this->jobs());
        self::assertSame([], $parameters->errors);
        self::assertSame(str_repeat('á', 254), $parameters->criteria->search);
    }

    /** @return list<JobView> */
    private function jobs(): array
    {
        return [new JobView('backend-developer', 'Backend Developer', 'Fictional job.')];
    }
}
