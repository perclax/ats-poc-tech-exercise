<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Domain\Application\ApplicationId;

final readonly class GetApplicationDetail
{
    public function __construct(public ApplicationId $applicationId)
    {
    }
}
