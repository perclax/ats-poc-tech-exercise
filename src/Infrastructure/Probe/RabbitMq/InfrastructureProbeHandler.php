<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe\RabbitMq;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InfrastructureProbeHandler
{
    public function __construct(private ProbeReceiptStore $receiptStore)
    {
    }

    public function __invoke(InfrastructureProbeMessage $message): void
    {
        $this->receiptStore->record($message->correlationId);
    }
}
