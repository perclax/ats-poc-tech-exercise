<?php

declare(strict_types=1);

namespace App\Applications\Domain\Enrichment;

final readonly class EnrichmentResult
{
    public string $summary;

    public function __construct(string $summary, public int $score)
    {
        $summary = trim($summary);
        if ('' === $summary) {
            throw new \InvalidArgumentException('The enrichment summary must not be empty.');
        }

        if ($score < 0 || $score > 100) {
            throw new \InvalidArgumentException('The enrichment score must be between 0 and 100.');
        }

        $this->summary = $summary;
    }
}
