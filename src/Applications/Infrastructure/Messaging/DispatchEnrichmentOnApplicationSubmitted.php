<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Port\EnrichmentDispatcher;
use App\Applications\Domain\Event\ApplicationSubmitted;

final readonly class DispatchEnrichmentOnApplicationSubmitted
{
    public function __construct(private EnrichmentDispatcher $enrichmentDispatcher)
    {
    }

    public function __invoke(ApplicationSubmitted $event): void
    {
        $this->enrichmentDispatcher->dispatch(new EnrichApplication($event->applicationId));
    }
}
