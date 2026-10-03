<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Identity;

use App\Applications\Application\Port\ApplicationIdGenerator;
use App\Applications\Domain\Application\ApplicationId;
use Symfony\Component\Uid\Uuid;

final class SymfonyUuidV7ApplicationIdGenerator implements ApplicationIdGenerator
{
    public function generate(): ApplicationId
    {
        return ApplicationId::fromString(Uuid::v7()->toRfc4122());
    }
}
