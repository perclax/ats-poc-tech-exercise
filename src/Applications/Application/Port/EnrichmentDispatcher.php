<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Application\Command\EnrichApplication;

interface EnrichmentDispatcher
{
    public function dispatch(EnrichApplication $command): void;
}
