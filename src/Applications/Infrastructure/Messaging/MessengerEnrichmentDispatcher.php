<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Application\Port\EnrichmentDispatcher;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class MessengerEnrichmentDispatcher implements EnrichmentDispatcher
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function dispatch(EnrichApplication $command): void
    {
        try {
            $this->messageBus->dispatch($command, [new EnrichmentAttemptStamp(bin2hex(random_bytes(16)))]);
        } catch (TransportException $exception) {
            throw new EnrichmentDispatchFailed('The enrichment message could not be dispatched.', previous: $exception);
        }
    }
}
