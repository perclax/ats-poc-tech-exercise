<?php

declare(strict_types=1);

namespace App\Applications\Domain\Application;

enum ApplicationStatus: string
{
    case RECEIVED = 'received';
}
