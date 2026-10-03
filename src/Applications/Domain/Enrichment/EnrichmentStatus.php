<?php

declare(strict_types=1);

namespace App\Applications\Domain\Enrichment;

enum EnrichmentStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
