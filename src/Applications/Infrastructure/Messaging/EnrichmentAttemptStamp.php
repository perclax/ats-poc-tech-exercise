<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class EnrichmentAttemptStamp implements StampInterface
{
    public function __construct(public string $attemptId)
    {
        new EnrichmentAttemptId($attemptId);
    }
}
