<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Domain\Application\ApplicationId;

interface ApplicationIdGenerator
{
    public function generate(): ApplicationId;
}
