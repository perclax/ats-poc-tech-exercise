<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Application\Query;

use App\Applications\Application\Port\ApplicationReadRepository;
use App\Applications\Application\Query\ApplicationListItem;
use App\Applications\Application\Query\ApplicationSearchCriteria;
use App\Applications\Application\Query\ListApplications;
use App\Applications\Application\Query\ListApplicationsHandler;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use PHPUnit\Framework\TestCase;

final class ListApplicationsHandlerTest extends TestCase
{
    public function testItPassesCriteriaUnchangedAndReturnsReadModels(): void
    {
        $criteria = new ApplicationSearchCriteria('Candidate');
        $item = new ApplicationListItem('018f47a2-7b3c-7def-8123-123456789abc', 'Candidate', 'candidate@example.test', 'backend-developer', 'Backend Developer', ApplicationStatus::RECEIVED, EnrichmentStatus::COMPLETED, 0, new \DateTimeImmutable('2026-10-05T10:00:00Z'));
        $repository = $this->createMock(ApplicationReadRepository::class);
        $repository->expects(self::once())->method('search')->with(self::identicalTo($criteria))->willReturn([$item]);
        $repository->expects(self::never())->method('findDetail');
        self::assertSame([$item], (new ListApplicationsHandler($repository))(new ListApplications($criteria)));
    }
}
