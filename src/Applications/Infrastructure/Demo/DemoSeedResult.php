<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Demo;

final readonly class DemoSeedResult
{
    public function __construct(public bool $skipped, public int $created, public int $alreadyPresent)
    {
    }
}
