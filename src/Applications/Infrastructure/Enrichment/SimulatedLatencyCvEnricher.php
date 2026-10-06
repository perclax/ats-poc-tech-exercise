<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Enrichment;

use App\Applications\Application\Port\CvEnricher;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Job\Job;

/**
 * Simulates the response time of a real LLM call so the pending and processing states are observable in the demo.
 */
final readonly class SimulatedLatencyCvEnricher implements CvEnricher
{
    public function __construct(
        private CvEnricher $inner,
        private int $latencyMilliseconds,
    ) {
        if ($latencyMilliseconds < 0) {
            throw new \InvalidArgumentException('The simulated latency must not be negative.');
        }
    }

    public function enrich(string $cvText, Job $job): EnrichmentResult
    {
        usleep($this->latencyMilliseconds * 1_000);

        return $this->inner->enrich($cvText, $job);
    }
}
