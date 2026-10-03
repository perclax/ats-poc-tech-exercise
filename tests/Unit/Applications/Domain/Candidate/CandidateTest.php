<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Domain\Candidate;

use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Candidate::class)]
#[CoversClass(EmailAddress::class)]
final class CandidateTest extends TestCase
{
    public function testItNormalizesRequiredAndOptionalContactData(): void
    {
        $candidate = new Candidate('  Prince  ', new EmailAddress(' candidate@example.test '), '  +34 600-123-456  ');

        self::assertSame('Prince', $candidate->fullName);
        self::assertSame('candidate@example.test', $candidate->email->value);
        self::assertSame('+34 600-123-456', $candidate->phone);
        self::assertNull(new Candidate('Ada', new EmailAddress('ada@example.test'), '  ')->phone);
    }

    #[DataProvider('invalidNames')]
    public function testItRejectsInvalidNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Candidate($name, new EmailAddress('valid@example.test'), null);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => ['   '];
        yield 'over 150 multibyte characters' => [str_repeat('á', 151)];
    }

    public function testItRejectsAnOverlongPhone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Candidate('Ada', new EmailAddress('ada@example.test'), str_repeat('1', 31));
    }

    #[DataProvider('invalidEmails')]
    public function testItRejectsInvalidEmails(string $email): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailAddress($email);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmails(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['not-an-email'];
        yield 'over 254 characters' => [str_repeat('a', 244).'@example.test'];
    }
}
