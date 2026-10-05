<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Application\Port\ApplicationReadRepository;

final readonly class GetApplicationDetailHandler
{
    public function __construct(private ApplicationReadRepository $applications)
    {
    }

    public function __invoke(GetApplicationDetail $query): ?ApplicationDetail
    {
        return $this->applications->findDetail($query->applicationId);
    }
}
