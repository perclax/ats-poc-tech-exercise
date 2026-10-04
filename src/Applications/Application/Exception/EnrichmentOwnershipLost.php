<?php

declare(strict_types=1);

namespace App\Applications\Application\Exception;

use App\Applications\Domain\Application\ApplicationId;

final class EnrichmentOwnershipLost extends \RuntimeException
{
    public static function forApplication(ApplicationId $id): self
    {
        return new self(\sprintf('The enrichment claim for application "%s" is no longer owned by this attempt.', $id->value));
    }
}
