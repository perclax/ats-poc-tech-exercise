<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Port\EventPublisher;
use App\Applications\Domain\Event\ApplicationSubmitted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class SymfonyEventPublisher implements EventPublisher
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
    {
    }

    public function publish(ApplicationSubmitted $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }
}
