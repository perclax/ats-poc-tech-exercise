<?php

declare(strict_types=1);

namespace App\Applications\Application\Enrichment;

final readonly class EnrichmentAttemptId
{
    public function __construct(public string $value)
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $value)) {
            throw new \InvalidArgumentException('The enrichment attempt ID must be 32 lowercase hexadecimal characters.');
        }
    }
}
