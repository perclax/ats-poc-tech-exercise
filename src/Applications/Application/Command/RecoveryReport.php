<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

final readonly class RecoveryReport
{
    public function __construct(
        public int $eligible,
        public int $reset,
        public int $dispatched,
        public int $skipped,
        public int $dispatchFailures,
        public bool $dryRun,
    ) {
    }

    public function succeeded(): bool
    {
        return 0 === $this->dispatchFailures;
    }
}
