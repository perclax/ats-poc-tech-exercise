<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Event\ApplicationSubmitted;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessagePrivacyTest extends TestCase
{
    /** @return iterable<string, array{object}> */
    public static function messages(): iterable
    {
        $id = ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');

        yield 'domain event' => [new ApplicationSubmitted($id)];
        yield 'asynchronous command' => [new EnrichApplication($id)];
    }

    #[DataProvider('messages')]
    public function testMessageContainsOnlyTheApplicationIdentifier(object $message): void
    {
        $properties = (new \ReflectionClass($message))->getProperties(\ReflectionProperty::IS_PUBLIC);

        self::assertCount(1, $properties);
        self::assertSame('applicationId', $properties[0]->getName());
        self::assertSame(
            '018f47a2-7b3c-7def-8123-123456789abc',
            $properties[0]->getValue($message)->value,
        );
        self::assertStringNotContainsString('example.test', serialize($message));
        self::assertStringNotContainsString('CV', serialize($message));
    }
}
