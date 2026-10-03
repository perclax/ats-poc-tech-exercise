<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;

interface JobCatalog
{
    /** @return list<Job> */
    public function all(): array;

    public function find(JobId $id): ?Job;
}
