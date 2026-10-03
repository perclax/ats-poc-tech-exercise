<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Time;

use App\Applications\Application\Port\Clock;

final class SystemUtcClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
