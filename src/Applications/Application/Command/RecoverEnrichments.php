<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

final readonly class RecoverEnrichments
{
    public function __construct(
        public int $staleAfterSeconds = 900,
        public int $limit = 100,
        public bool $dryRun = false,
    ) {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The stale threshold must be at least one second.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The recovery limit must be at least one.');
        }
    }
}
