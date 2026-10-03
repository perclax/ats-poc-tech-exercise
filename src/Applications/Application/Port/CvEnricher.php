<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Job\Job;

interface CvEnricher
{
    public function enrich(string $cvText, Job $job): EnrichmentResult;
}
