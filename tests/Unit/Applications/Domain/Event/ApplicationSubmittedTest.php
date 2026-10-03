<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Domain\Event;

use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Event\ApplicationSubmitted;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApplicationSubmitted::class)]
final class ApplicationSubmittedTest extends TestCase
{
    public function testItCarriesOnlyTheApplicationIdentifier(): void
    {
        $id = ApplicationId::fromString('018f22e2-8f66-7f59-9a3d-1c2ecb22a261');
        $event = new ApplicationSubmitted($id);

        self::assertSame($id, $event->applicationId);
        self::assertSame(['applicationId'], array_keys(get_object_vars($event)));
    }
}
