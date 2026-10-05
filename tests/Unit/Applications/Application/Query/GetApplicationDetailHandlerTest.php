<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Application\Query;

use App\Applications\Application\Port\ApplicationReadRepository;
use App\Applications\Application\Query\ApplicationDetail;
use App\Applications\Application\Query\GetApplicationDetail;
use App\Applications\Application\Query\GetApplicationDetailHandler;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use PHPUnit\Framework\TestCase;

final class GetApplicationDetailHandlerTest extends TestCase
{
    public function testItReturnsTheFoundDetailUnchanged(): void
    {
        $id = ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');
        $detail = new ApplicationDetail($id->value, 'Candidate', 'candidate@example.test', null, 'backend-developer', 'Backend Developer', 'Description', null, 'CV', ApplicationStatus::RECEIVED, EnrichmentStatus::PENDING, null, null, new \DateTimeImmutable('2026-10-05T10:00:00Z'), null);
        $repository = $this->createMock(ApplicationReadRepository::class);
        $repository->expects(self::once())->method('findDetail')->with(self::identicalTo($id))->willReturn($detail);
        $repository->expects(self::never())->method('search');
        self::assertSame($detail, (new GetApplicationDetailHandler($repository))(new GetApplicationDetail($id)));
    }

    public function testItReturnsNullForMissingApplications(): void
    {
        $id = ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');
        $repository = $this->createMock(ApplicationReadRepository::class);
        $repository->expects(self::once())->method('findDetail')->with(self::identicalTo($id))->willReturn(null);
        self::assertNull((new GetApplicationDetailHandler($repository))(new GetApplicationDetail($id)));
    }
}
