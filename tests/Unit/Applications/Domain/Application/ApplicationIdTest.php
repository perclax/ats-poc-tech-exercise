<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Domain\Application;

use App\Applications\Domain\Application\ApplicationId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplicationId::class)]
final class ApplicationIdTest extends TestCase
{
    public function testItAcceptsACanonicalUuidWithoutGeneratingIt(): void
    {
        $id = ApplicationId::fromString('018f22e2-8f66-7f59-9a3d-1c2ecb22a261');

        self::assertSame('018f22e2-8f66-7f59-9a3d-1c2ecb22a261', $id->value);
        self::assertSame($id->value, (string) $id);
    }

    #[DataProvider('invalidIds')]
    public function testItRejectsNonCanonicalUuids(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ApplicationId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['018F22E2-8F66-7F59-9A3D-1C2ECB22A261'];
        yield 'without separators' => ['018f22e28f667f599a3d1c2ecb22a261'];
        yield 'invalid variant' => ['018f22e2-8f66-7f59-7a3d-1c2ecb22a261'];
    }
}
