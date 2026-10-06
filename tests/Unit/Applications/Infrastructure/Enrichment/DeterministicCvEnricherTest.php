<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Enrichment;

use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;
use App\Applications\Domain\Job\SkillGroup;
use App\Applications\Infrastructure\Enrichment\DeterministicCvEnricher;
use App\Applications\Infrastructure\Job\VersionControlledJobCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeterministicCvEnricherTest extends TestCase
{
    #[DataProvider('matchingCvTexts')]
    public function testItMatchesCaseUnicodePunctuationAndAliases(string $cvText, int $score, string $summary): void
    {
        $job = (new VersionControlledJobCatalog())->find(new JobId('backend-developer'));
        self::assertNotNull($job);

        $result = (new DeterministicCvEnricher())->enrich($cvText, $job);

        self::assertSame($score, $result->score);
        self::assertSame($summary, $result->summary);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function matchingCvTexts(): iterable
    {
        yield 'case insensitive and punctuation' => [
            'Built services with (PHP), SYMFONY; and MongoDB.',
            75,
            'Mock analysis: Matched 3 of 4 expected skill groups: PHP, Symfony, Databases.',
        ];
        yield 'unicode and multi-word alias' => [
            "Diseñé APIs — REST\u{00A0}API — para producción.",
            25,
            'Mock analysis: Matched 1 of 4 expected skill groups: REST APIs.',
        ];
        yield 'plural alias is case insensitive and boundary safe' => [
            'Built services around (rEsT APIs), with documented contracts.',
            25,
            'Mock analysis: Matched 1 of 4 expected skill groups: REST APIs.',
        ];
        yield 'REST aliases count their group once' => [
            'Delivered REST API, REST APIs, and RESTful endpoints.',
            25,
            'Mock analysis: Matched 1 of 4 expected skill groups: REST APIs.',
        ];
        yield 'unrelated rest does not match' => [
            'Documented the rest of the project.',
            0,
            'Mock analysis: Matched 0 of 4 expected skill groups.',
        ];
        yield 'all groups' => [
            'PHP Symfony SQL and RESTful services.',
            100,
            'Mock analysis: Matched 4 of 4 expected skill groups: PHP, Symfony, Databases, REST APIs.',
        ];
        yield 'no groups' => [
            'Technical writing and product discovery.',
            0,
            'Mock analysis: Matched 0 of 4 expected skill groups.',
        ];
    }

    public function testItUsesBoundariesAndCountsEachGroupOnlyOnce(): void
    {
        $job = new Job(new JobId('test-role'), 'Test role', 'Boundary test', [
            new SkillGroup('React', ['react', 'react.js']),
            new SkillGroup('SQL', ['sql']),
        ]);

        $result = (new DeterministicCvEnricher())->enrich('reactive React react.js react.js nosqlsql', $job);

        self::assertSame(50, $result->score);
        self::assertSame('Mock analysis: Matched 1 of 2 expected skill groups: React.', $result->summary);
    }

    public function testItMatchesAliasesContainingSpecialCharactersLiterally(): void
    {
        $job = new Job(new JobId('special-role'), 'Special role', 'Special aliases', [
            new SkillGroup('C++', ['c++']),
            new SkillGroup('C#', ['c#']),
            new SkillGroup('.NET', ['.net']),
            new SkillGroup('React.js', ['react.js']),
            new SkillGroup('Objective-C', ['objective-c']),
        ]);

        $result = (new DeterministicCvEnricher())->enrich('C++ C# .NET reactXjs React.js objective-c objectiveXc', $job);

        self::assertSame(100, $result->score);
        self::assertSame('Mock analysis: Matched 5 of 5 expected skill groups: C++, C#, .NET, React.js, Objective-C.', $result->summary);
    }

    public function testItProducesStableRoundingAndResults(): void
    {
        $job = (new VersionControlledJobCatalog())->find(new JobId('fullstack-developer'));
        self::assertNotNull($job);
        $enricher = new DeterministicCvEnricher();

        $oneOfEight = $enricher->enrich('PHP', $job);
        $threeOfEight = $enricher->enrich('PHP Symfony React', $job);

        self::assertSame(13, $oneOfEight->score);
        self::assertSame(38, $threeOfEight->score);
        self::assertEquals($threeOfEight, $enricher->enrich('PHP Symfony React', $job));
    }

    public function testItTreatsCanonicallyEquivalentUnicodeAsStableInput(): void
    {
        $job = new Job(new JobId('unicode-role'), 'Unicode role', 'Unicode alias', [
            new SkillGroup('Café', ["cafe\u{0301}"]),
        ]);
        $enricher = new DeterministicCvEnricher();

        self::assertEquals($enricher->enrich("cafe\u{0301}", $job), $enricher->enrich('café', $job));
    }
}
