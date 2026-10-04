<?php

declare(strict_types=1);

namespace App\Applications\Application\Enrichment;

enum EnrichmentClaimOutcome: string
{
    case CLAIMED = 'claimed';
    case RESUMED = 'resumed';
    case COMPETING = 'competing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case UNKNOWN = 'unknown';
}
