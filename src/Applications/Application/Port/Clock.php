<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
