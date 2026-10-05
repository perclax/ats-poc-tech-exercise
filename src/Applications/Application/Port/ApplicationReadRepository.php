<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Application\Query\ApplicationDetail;
use App\Applications\Application\Query\ApplicationListItem;
use App\Applications\Application\Query\ApplicationSearchCriteria;
use App\Applications\Domain\Application\ApplicationId;

interface ApplicationReadRepository
{
    /**
     * Return at most 100 matching applications ordered by appliedAt and ID descending.
     *
     * @return list<ApplicationListItem>
     */
    public function search(ApplicationSearchCriteria $criteria): array;

    public function findDetail(ApplicationId $id): ?ApplicationDetail;
}
