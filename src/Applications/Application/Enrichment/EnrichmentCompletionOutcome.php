<?php

declare(strict_types=1);

namespace App\Applications\Application\Enrichment;

enum EnrichmentCompletionOutcome: string
{
    case COMPLETED = 'completed';
    case ALREADY_COMPLETED = 'already_completed';
    case OWNERSHIP_LOST = 'ownership_lost';
}
