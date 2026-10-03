<?php

declare(strict_types=1);

namespace App\Applications\Domain\Event;

use App\Applications\Domain\Application\ApplicationId;

final readonly class ApplicationSubmitted
{
    public function __construct(public ApplicationId $applicationId)
    {
    }
}
