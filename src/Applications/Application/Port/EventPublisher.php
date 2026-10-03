<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Domain\Event\ApplicationSubmitted;

interface EventPublisher
{
    public function publish(ApplicationSubmitted $event): void;
}
