<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

use App\Applications\Application\Port\JobCatalog;

final readonly class ListJobsHandler
{
    public function __construct(private JobCatalog $jobs)
    {
    }

    /** @return list<JobView> */
    public function __invoke(ListJobs $query): array
    {
        return array_map(
            static fn ($job): JobView => new JobView($job->id->value, $job->title, $job->description),
            $this->jobs->all(),
        );
    }
}
