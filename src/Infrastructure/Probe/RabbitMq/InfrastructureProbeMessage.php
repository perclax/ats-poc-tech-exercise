<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe\RabbitMq;

final readonly class InfrastructureProbeMessage
{
    public function __construct(public string $correlationId)
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $correlationId)) {
            throw new \InvalidArgumentException('The probe correlation ID must be 64 lowercase hexadecimal characters.');
        }
    }
}
