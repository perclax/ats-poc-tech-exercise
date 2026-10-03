<?php

declare(strict_types=1);

namespace App\Tests\Integration\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Command\SubmitApplication;
use App\Applications\Application\Command\SubmitApplicationHandler;
use App\Tests\Support\MongoDbTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ApplicationSubmissionMessagingTest extends MongoDbTestCase
{
    public function testSubmissionDispatchesIdentifierOnlyToTheInMemoryEnrichmentTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.enrichment_async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $handler = self::getContainer()->get(SubmitApplicationHandler::class);
        self::assertInstanceOf(SubmitApplicationHandler::class, $handler);

        $result = $handler(new SubmitApplication(
            'Ada Lovelace',
            'ada@example.test',
            null,
            'backend-developer',
            null,
            'Private CV text',
        ));

        self::assertTrue($result->analysisQueued);
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(EnrichApplication::class, $message);
        self::assertSame($result->applicationId->value, $message->applicationId->value);
        self::assertStringNotContainsString('ada@example.test', serialize($message));
        self::assertStringNotContainsString('Private CV text', serialize($message));
    }
}
