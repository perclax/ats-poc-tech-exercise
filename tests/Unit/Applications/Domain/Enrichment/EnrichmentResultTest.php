<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Domain\Enrichment;

use App\Applications\Domain\Enrichment\EnrichmentResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnrichmentResult::class)]
final class EnrichmentResultTest extends TestCase
{
    #[DataProvider('boundaryScores')]
    public function testItAcceptsBoundaryScores(int $score): void
    {
        $result = new EnrichmentResult('  Evidence-based summary.  ', $score);

        self::assertSame('Evidence-based summary.', $result->summary);
        self::assertSame($score, $result->score);
    }

    /** @return iterable<string, array{int}> */
    public static function boundaryScores(): iterable
    {
        yield 'zero is completed data' => [0];
        yield 'one hundred' => [100];
    }

    #[DataProvider('invalidScores')]
    public function testItRejectsScoresOutsideTheBoundaries(int $score): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EnrichmentResult('Summary', $score);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidScores(): iterable
    {
        yield 'below zero' => [-1];
        yield 'above one hundred' => [101];
    }

    public function testItRejectsAnEmptySummary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EnrichmentResult('  ', 50);
    }
}
