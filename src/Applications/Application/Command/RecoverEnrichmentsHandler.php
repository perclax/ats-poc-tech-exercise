<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\EnrichmentDispatcher;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use Psr\Log\LoggerInterface;

final readonly class RecoverEnrichmentsHandler
{
    public function __construct(
        private ApplicationEnrichmentRepository $applications,
        private EnrichmentDispatcher $dispatcher,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecoverEnrichments $command): RecoveryReport
    {
        $staleBefore = $this->clock->now()->sub(new \DateInterval(\sprintf('PT%dS', $command->staleAfterSeconds)));
        $candidates = $this->applications->recoveryCandidates($staleBefore, $command->limit);
        if ($command->dryRun) {
            return new RecoveryReport(\count($candidates), 0, 0, 0, 0, true);
        }

        $reset = 0;
        $dispatched = 0;
        $skipped = 0;
        $dispatchFailures = 0;

        foreach ($candidates as $candidate) {
            if (EnrichmentStatus::PROCESSING === $candidate->status) {
                if (!$this->applications->resetStaleProcessing($candidate, $staleBefore)) {
                    ++$skipped;
                    continue;
                }
                ++$reset;
            }

            try {
                $this->dispatcher->dispatch(new EnrichApplication($candidate->applicationId));
                ++$dispatched;
                $this->logger->info('Enrichment recovery redispatched an application.', [
                    'application_id' => $candidate->applicationId->value,
                    'previous_state' => $candidate->status->value,
                ]);
            } catch (EnrichmentDispatchFailed) {
                ++$dispatchFailures;
                $this->logger->error('Enrichment recovery could not redispatch an application.', [
                    'application_id' => $candidate->applicationId->value,
                    'previous_state' => $candidate->status->value,
                ]);
            }
        }

        return new RecoveryReport(\count($candidates), $reset, $dispatched, $skipped, $dispatchFailures, false);
    }
}
