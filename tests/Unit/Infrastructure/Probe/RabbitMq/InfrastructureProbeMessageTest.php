<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Probe\RabbitMq;

use App\Infrastructure\Probe\RabbitMq\InfrastructureProbeMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InfrastructureProbeMessage::class)]
final class InfrastructureProbeMessageTest extends TestCase
{
    public function testItAcceptsAValidCorrelationId(): void
    {
        $message = new InfrastructureProbeMessage(str_repeat('a', 64));

        self::assertSame(str_repeat('a', 64), $message->correlationId);
    }

    public function testItRejectsAnInvalidCorrelationId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InfrastructureProbeMessage('../invalid');
    }
}
