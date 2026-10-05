<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaimOutcome;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Exception\EnrichmentOwnershipLost;
use App\Applications\Application\Exception\UnknownJob;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\CvEnricher;
use App\Applications\Application\Port\JobCatalog;
use Psr\Log\LoggerInterface;

final readonly class EnrichApplicationHandler
{
    public function __construct(
        private ApplicationEnrichmentRepository $applications,
        private JobCatalog $jobs,
        private CvEnricher $cvEnricher,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnrichApplication $command, EnrichmentAttemptId $attemptId): void
    {
        $applicationId = $command->applicationId;
        $this->logger->info('Enrichment message received.', ['application_id' => $applicationId->value]);

        $claimResult = $this->applications->claim($applicationId, $attemptId, $this->clock->now());
        $claim = $claimResult->claim;
        if (null === $claim) {
            $this->logger->info('Enrichment claim skipped.', [
                'application_id' => $applicationId->value,
                'outcome' => $claimResult->outcome->value,
                'state' => $claimResult->currentStatus?->value,
            ]);

            return;
        }

        $this->logger->info('Enrichment claim acquired.', [
            'application_id' => $applicationId->value,
            'outcome' => $claimResult->outcome->value,
        ]);

        $job = $this->jobs->find($claim->application->jobId());
        if (null === $job) {
            throw UnknownJob::withId($claim->application->jobId()->value);
        }

        $result = $this->cvEnricher->enrich($claim->application->cvText(), $job);
        $claim->application->completeEnrichment($result, $this->clock->now());
        $completion = $this->applications->complete($claim);
        if (EnrichmentCompletionOutcome::OWNERSHIP_LOST === $completion) {
            throw EnrichmentOwnershipLost::forApplication($applicationId);
        }

        $this->logger->info('Enrichment completed.', [
            'application_id' => $applicationId->value,
            'outcome' => $completion->value,
            'state' => EnrichmentClaimOutcome::COMPLETED->value,
        ]);
    }
}
