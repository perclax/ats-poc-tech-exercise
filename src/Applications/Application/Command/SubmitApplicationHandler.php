<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Application\Exception\UnknownJob;
use App\Applications\Application\Port\ApplicationIdGenerator;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\EventPublisher;
use App\Applications\Application\Port\JobCatalog;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Event\ApplicationSubmitted;
use App\Applications\Domain\Job\JobId;

final readonly class SubmitApplicationHandler
{
    public function __construct(
        private ApplicationRepository $applications,
        private JobCatalog $jobs,
        private ApplicationIdGenerator $applicationIds,
        private Clock $clock,
        private EventPublisher $events,
    ) {
    }

    public function __invoke(SubmitApplication $command): SubmitApplicationResult
    {
        $jobId = new JobId($command->jobId);
        if (null === $this->jobs->find($jobId)) {
            throw UnknownJob::withId($command->jobId);
        }

        $application = new Application(
            $this->applicationIds->generate(),
            new Candidate($command->fullName, new EmailAddress($command->email), $command->phone),
            $jobId,
            $command->notes,
            $command->cvText,
            $this->clock->now(),
        );

        $this->applications->save($application);

        try {
            $this->events->publish(new ApplicationSubmitted($application->id()));
        } catch (EnrichmentDispatchFailed) {
            return new SubmitApplicationResult($application->id(), false);
        }

        return new SubmitApplicationResult($application->id(), true);
    }
}
