<?php

declare(strict_types=1);

namespace App\Applications\Domain\Application;

use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Enrichment\InvalidEnrichmentTransition;
use App\Applications\Domain\Job\JobId;

final class Application
{
    private ApplicationStatus $status = ApplicationStatus::RECEIVED;
    private EnrichmentStatus $enrichmentStatus = EnrichmentStatus::PENDING;
    private ?EnrichmentResult $enrichmentResult = null;
    private ?\DateTimeImmutable $enrichedAt = null;
    private readonly ?string $notes;
    private readonly string $cvText;

    public function __construct(
        private readonly ApplicationId $id,
        private readonly Candidate $candidate,
        private readonly JobId $jobId,
        ?string $notes,
        string $cvText,
        private readonly \DateTimeImmutable $appliedAt,
    ) {
        self::assertUtc($appliedAt, 'Application timestamp');

        if ('' === trim($cvText) || mb_strlen($cvText) > 30_000) {
            throw new \InvalidArgumentException('The CV text must not be empty and must be at most 30,000 characters.');
        }

        if (null !== $notes && mb_strlen($notes) > 2_000) {
            throw new \InvalidArgumentException('Notes must be at most 2,000 characters.');
        }

        $this->notes = null === $notes || '' === trim($notes) ? null : $notes;
        $this->cvText = $cvText;
    }

    public function id(): ApplicationId
    {
        return $this->id;
    }

    public function candidate(): Candidate
    {
        return $this->candidate;
    }

    public function jobId(): JobId
    {
        return $this->jobId;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function cvText(): string
    {
        return $this->cvText;
    }

    public function status(): ApplicationStatus
    {
        return $this->status;
    }

    public function enrichmentStatus(): EnrichmentStatus
    {
        return $this->enrichmentStatus;
    }

    public function appliedAt(): \DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function enrichmentResult(): ?EnrichmentResult
    {
        return $this->enrichmentResult;
    }

    public function enrichedAt(): ?\DateTimeImmutable
    {
        return $this->enrichedAt;
    }

    public function startEnrichment(): void
    {
        $this->transitionFrom(EnrichmentStatus::PENDING, EnrichmentStatus::PROCESSING);
    }

    public function completeEnrichment(EnrichmentResult $result, \DateTimeImmutable $enrichedAt): void
    {
        if (EnrichmentStatus::PROCESSING !== $this->enrichmentStatus) {
            throw InvalidEnrichmentTransition::from($this->enrichmentStatus, EnrichmentStatus::COMPLETED);
        }

        self::assertUtc($enrichedAt, 'Enrichment timestamp');
        if ($enrichedAt < $this->appliedAt) {
            throw new \InvalidArgumentException('The enrichment timestamp must not be earlier than the application timestamp.');
        }

        $this->enrichmentResult = $result;
        $this->enrichedAt = $enrichedAt;
        $this->enrichmentStatus = EnrichmentStatus::COMPLETED;
    }

    public function failEnrichment(): void
    {
        $this->transitionFrom(EnrichmentStatus::PROCESSING, EnrichmentStatus::FAILED);
    }

    private function transitionFrom(EnrichmentStatus $expected, EnrichmentStatus $target): void
    {
        if ($expected !== $this->enrichmentStatus) {
            throw InvalidEnrichmentTransition::from($this->enrichmentStatus, $target);
        }

        $this->enrichmentStatus = $target;
    }

    private static function assertUtc(\DateTimeImmutable $timestamp, string $label): void
    {
        if (0 !== $timestamp->getOffset()) {
            throw new \InvalidArgumentException(\sprintf('%s must represent UTC.', $label));
        }
    }
}
