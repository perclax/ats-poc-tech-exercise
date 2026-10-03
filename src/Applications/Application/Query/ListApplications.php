<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

final readonly class ListApplications
{
    public function __construct(public ApplicationSearchCriteria $criteria)
    {
    }
}
