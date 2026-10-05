<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Application\Port\ApplicationReadRepository;

final readonly class ListApplicationsHandler
{
    public function __construct(private ApplicationReadRepository $applications)
    {
    }

    /** @return list<ApplicationListItem> */
    public function __invoke(ListApplications $query): array
    {
        return $this->applications->search($query->criteria);
    }
}
