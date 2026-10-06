<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Infrastructure\Messaging\MessengerEnrichmentDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerEnrichmentDispatcherTest extends TestCase
{
    public function testItDispatchesTheIdentifierOnlyCommand(): void
    {
        $bus = new RecordingMessageBus();
        $command = $this->command();

        (new MessengerEnrichmentDispatcher($bus))->dispatch($command);

        self::assertSame($command, $bus->message);
    }

    public function testItConvertsOnlyTransportFailureToExpectedDispatchFailure(): void
    {
        $dispatcher = new MessengerEnrichmentDispatcher(new FailingMessageBus(new TransportException('RabbitMQ unavailable')));

        $this->expectException(EnrichmentDispatchFailed::class);

        $dispatcher->dispatch($this->command());
    }

    public function testUnexpectedProgrammingFailurePropagatesUnchanged(): void
    {
        $dispatcher = new MessengerEnrichmentDispatcher(new FailingMessageBus(new \LogicException('bad middleware')));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('bad middleware');

        $dispatcher->dispatch($this->command());
    }

    private function command(): EnrichApplication
    {
        return new EnrichApplication(ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc'));
    }
}

final class RecordingMessageBus implements MessageBusInterface
{
    public ?object $message = null;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->message = $message;

        return new Envelope($message, $stamps);
    }
}

final readonly class FailingMessageBus implements MessageBusInterface
{
    public function __construct(private \Throwable $failure)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        throw $this->failure;
    }
}
