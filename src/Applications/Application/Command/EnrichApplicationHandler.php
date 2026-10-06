<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

use App\Applications\Application\Exception\UnknownJob;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\CvEnricher;
use App\Applications\Application\Port\JobCatalog;
use Psr\Log\LoggerInterface;

final readonly class EnrichApplicationHandler
{
    public function __construct(
        private ApplicationRepository $applications,
        private JobCatalog $jobs,
        private CvEnricher $cvEnricher,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnrichApplication $command): void
    {
        $applicationId = $command->applicationId;
        $application = $this->applications->claimForEnrichment($applicationId);
        if (null === $application) {
            $this->logger->info('Enrichment skipped: application is not pending.', ['application_id' => $applicationId->value]);

            return;
        }

        $job = $this->jobs->find($application->jobId());
        if (null === $job) {
            throw UnknownJob::withId($application->jobId()->value);
        }

        $application->completeEnrichment($this->cvEnricher->enrich($application->cvText(), $job), $this->clock->now());
        $this->applications->completeEnrichment($application);

        $this->logger->info('Enrichment completed.', ['application_id' => $applicationId->value]);
    }
}
