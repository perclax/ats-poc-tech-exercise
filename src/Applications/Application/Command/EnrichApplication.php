<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

use App\Applications\Domain\Application\ApplicationId;

final readonly class EnrichApplication
{
    public function __construct(public ApplicationId $applicationId)
    {
    }
}
