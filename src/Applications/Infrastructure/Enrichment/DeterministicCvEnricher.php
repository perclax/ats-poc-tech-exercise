<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Enrichment;

use App\Applications\Application\Port\CvEnricher;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\SkillGroup;

final class DeterministicCvEnricher implements CvEnricher
{
    public function enrich(string $cvText, Job $job): EnrichmentResult
    {
        $normalizedCv = $this->normalize($cvText);
        $matchedGroups = [];

        foreach ($job->skillGroups as $skillGroup) {
            if ($this->matchesGroup($normalizedCv, $skillGroup)) {
                $matchedGroups[] = $skillGroup->name;
            }
        }

        $matched = \count($matchedGroups);
        $total = \count($job->skillGroups);
        $score = (int) round(($matched / $total) * 100, 0, \PHP_ROUND_HALF_UP);
        $summary = 0 === $matched
            ? \sprintf('Mock analysis: Matched 0 of %d expected skill groups.', $total)
            : \sprintf(
                'Mock analysis: Matched %d of %d expected skill groups: %s.',
                $matched,
                $total,
                implode(', ', $matchedGroups),
            );

        return new EnrichmentResult($summary, $score);
    }

    private function matchesGroup(string $normalizedCv, SkillGroup $skillGroup): bool
    {
        foreach ($skillGroup->aliases as $alias) {
            $normalizedAlias = $this->normalize($alias);
            $parts = preg_split('/[\p{Z}\s]+/u', $normalizedAlias);
            if (false === $parts || [] === $parts) {
                throw new \RuntimeException('Unable to build a skill alias pattern.');
            }
            $patternBody = implode('[\p{Z}\s]+', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts));

            if (1 === preg_match('/(?<![\p{L}\p{N}])'.$patternBody.'(?![\p{L}\p{N}])/u', $normalizedCv)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Enrichment input must be valid UTF-8.');
        }

        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (false === $normalized) {
            throw new \InvalidArgumentException('Enrichment input could not be normalized.');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        return mb_strtolower($normalized, 'UTF-8');
    }
}
