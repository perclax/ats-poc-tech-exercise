<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;

interface ApplicationRepository
{
    public function find(ApplicationId $id): ?Application;

    public function save(Application $application): void;
}
