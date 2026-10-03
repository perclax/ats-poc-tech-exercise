<?php

declare(strict_types=1);

namespace App\Applications\Domain\Enrichment;

final class InvalidEnrichmentTransition extends \DomainException
{
    public static function from(EnrichmentStatus $status, EnrichmentStatus $target): self
    {
        return new self(\sprintf('Cannot change enrichment status from "%s" to "%s".', $status->value, $target->value));
    }
}
