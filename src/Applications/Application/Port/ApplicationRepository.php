<?php

declare(strict_types=1);

namespace App\Applications\Application\Port;

use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;

interface ApplicationRepository
{
    public function find(ApplicationId $id): ?Application;

    public function save(Application $application): void;

    /**
     * Atomically moves a pending application to processing.
     * Returns null when the application is missing or not pending.
     */
    public function claimForEnrichment(ApplicationId $id): ?Application;

    /** Persists the result of a claimed application that completed its enrichment. */
    public function completeEnrichment(Application $application): void;

    /** Returns a processing application to pending so a retry can claim it again. */
    public function releaseEnrichment(ApplicationId $id): void;

    /** Marks a processing application as failed after retries are exhausted. */
    public function failEnrichment(ApplicationId $id): void;
}
