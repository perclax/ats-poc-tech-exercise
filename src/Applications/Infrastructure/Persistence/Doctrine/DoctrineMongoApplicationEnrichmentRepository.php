<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaim;
use App\Applications\Application\Enrichment\EnrichmentClaimOutcome;
use App\Applications\Application\Enrichment\EnrichmentClaimResult;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Enrichment\RecoveryCandidate;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use MongoDB\UpdateResult;

final readonly class DoctrineMongoApplicationEnrichmentRepository implements ApplicationEnrichmentRepository
{
    public function __construct(
        private DocumentManager $documentManager,
        private ApplicationDocumentMapper $mapper,
    ) {
    }

    public function claim(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): EnrichmentClaimResult
    {
        $this->assertUtc($startedAt);

        $document = $this->claimPending($id, $attemptId, $startedAt);
        if ($document instanceof ApplicationDocument) {
            return EnrichmentClaimResult::acquired(new EnrichmentClaim($this->mapper->toDomain($document), $attemptId, false));
        }

        $document = $this->resumeOwnedProcessing($id, $attemptId, $startedAt);
        if ($document instanceof ApplicationDocument) {
            return EnrichmentClaimResult::acquired(new EnrichmentClaim($this->mapper->toDomain($document), $attemptId, true));
        }

        $current = $this->findDocument($id);
        if (!$current instanceof ApplicationDocument) {
            return EnrichmentClaimResult::skipped(EnrichmentClaimOutcome::UNKNOWN, null);
        }

        $status = EnrichmentStatus::from($current->enrichmentStatus);

        return EnrichmentClaimResult::skipped(match ($status) {
            EnrichmentStatus::COMPLETED => EnrichmentClaimOutcome::COMPLETED,
            EnrichmentStatus::FAILED => EnrichmentClaimOutcome::FAILED,
            EnrichmentStatus::PROCESSING => EnrichmentClaimOutcome::COMPETING,
            EnrichmentStatus::PENDING => EnrichmentClaimOutcome::COMPETING,
        }, $status);
    }

    public function complete(EnrichmentClaim $claim): EnrichmentCompletionOutcome
    {
        $result = $claim->application->enrichmentResult();
        $enrichedAt = $claim->application->enrichedAt();
        if (EnrichmentStatus::COMPLETED !== $claim->application->enrichmentStatus() || null === $result || null === $enrichedAt) {
            throw new \LogicException('Only a completed domain application can be persisted as completed.');
        }

        $updateResult = $this->baseUpdate($claim->application->id(), EnrichmentStatus::PROCESSING, $claim->attemptId)
            ->field('enrichmentStatus')->set(EnrichmentStatus::COMPLETED->value)
            ->field('enrichmentSummary')->set($result->summary)
            ->field('enrichmentScore')->set($result->score)
            ->field('enrichedAt')->set($enrichedAt)
            ->field('processingStartedAt')->set(null)
            ->field('processingAttemptId')->set(null)
            ->getQuery()
            ->execute();

        if (!$updateResult instanceof UpdateResult) {
            throw new \RuntimeException('MongoDB did not return an update result for enrichment completion.');
        }
        if (1 === $updateResult->getModifiedCount()) {
            $this->documentManager->clear();

            return EnrichmentCompletionOutcome::COMPLETED;
        }

        $current = $this->findDocument($claim->application->id());
        if ($current instanceof ApplicationDocument && EnrichmentStatus::COMPLETED->value === $current->enrichmentStatus) {
            return EnrichmentCompletionOutcome::ALREADY_COMPLETED;
        }

        return EnrichmentCompletionOutcome::OWNERSHIP_LOST;
    }

    public function returnOwnedClaimToPending(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        return $this->transitionOwnedClaim($id, $attemptId, EnrichmentStatus::PENDING);
    }

    public function markOwnedClaimFailed(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        return $this->transitionOwnedClaim($id, $attemptId, EnrichmentStatus::FAILED);
    }

    public function recoveryCandidates(\DateTimeImmutable $staleBefore, int $limit): array
    {
        $this->assertUtc($staleBefore);
        if ($limit < 1) {
            throw new \InvalidArgumentException('The recovery limit must be at least one.');
        }

        $staleBuilder = $this->documentManager->createQueryBuilder(ApplicationDocument::class);
        $staleBuilder
            ->field('enrichmentStatus')->equals(EnrichmentStatus::PROCESSING->value)
            ->addOr(
                $staleBuilder->expr()->field('processingStartedAt')->lte($staleBefore),
                $staleBuilder->expr()->field('processingStartedAt')->equals(null),
            )
            ->sort('processingStartedAt', 'asc')
            ->sort('id', 'asc')
            ->limit($limit);

        $candidates = [];
        $staleDocuments = $staleBuilder->getQuery()->execute();
        if (!is_iterable($staleDocuments)) {
            throw new \RuntimeException('MongoDB did not return iterable stale recovery candidates.');
        }
        foreach ($staleDocuments as $document) {
            if ($document instanceof ApplicationDocument) {
                $candidates[] = $this->recoveryCandidate($document);
            }
        }

        $remaining = $limit - \count($candidates);
        if ($remaining < 1) {
            return $candidates;
        }

        $pendingDocuments = $this->documentManager->createQueryBuilder(ApplicationDocument::class)
            ->field('enrichmentStatus')->equals(EnrichmentStatus::PENDING->value)
            ->sort('id', 'asc')
            ->limit($remaining)
            ->getQuery()
            ->execute();

        if (!is_iterable($pendingDocuments)) {
            throw new \RuntimeException('MongoDB did not return iterable pending recovery candidates.');
        }
        foreach ($pendingDocuments as $document) {
            if ($document instanceof ApplicationDocument) {
                $candidates[] = $this->recoveryCandidate($document);
            }
        }

        return $candidates;
    }

    public function resetStaleProcessing(RecoveryCandidate $candidate, \DateTimeImmutable $staleBefore): bool
    {
        if (EnrichmentStatus::PROCESSING !== $candidate->status) {
            return false;
        }

        $builder = $this->baseUpdate($candidate->applicationId, EnrichmentStatus::PROCESSING, $candidate->attemptId);
        $builder->addOr(
            $builder->expr()->field('processingStartedAt')->lte($staleBefore),
            $builder->expr()->field('processingStartedAt')->equals(null),
        );

        $result = $builder
            ->field('enrichmentStatus')->set(EnrichmentStatus::PENDING->value)
            ->field('processingStartedAt')->set(null)
            ->field('processingAttemptId')->set(null)
            ->getQuery()
            ->execute();

        $this->documentManager->clear();

        return $result instanceof UpdateResult && 1 === $result->getModifiedCount();
    }

    private function claimPending(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): ?ApplicationDocument
    {
        return $this->findAndUpdateClaim($id, EnrichmentStatus::PENDING, null, $attemptId, $startedAt);
    }

    private function resumeOwnedProcessing(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): ?ApplicationDocument
    {
        return $this->findAndUpdateClaim($id, EnrichmentStatus::PROCESSING, $attemptId, $attemptId, $startedAt);
    }

    private function findAndUpdateClaim(
        ApplicationId $id,
        EnrichmentStatus $status,
        ?EnrichmentAttemptId $currentAttemptId,
        EnrichmentAttemptId $newAttemptId,
        \DateTimeImmutable $startedAt,
    ): ?ApplicationDocument {
        $builder = $this->documentManager->createQueryBuilder(ApplicationDocument::class)
            ->findAndUpdate()
            ->field('id')->equals($id->value)
            ->field('enrichmentStatus')->equals($status->value);

        if ($currentAttemptId instanceof EnrichmentAttemptId) {
            $builder->field('processingAttemptId')->equals($currentAttemptId->value);
        }

        $result = $builder
            ->field('enrichmentStatus')->set(EnrichmentStatus::PROCESSING->value)
            ->field('processingStartedAt')->set($startedAt)
            ->field('processingAttemptId')->set($newAttemptId->value)
            ->returnNew()
            ->getQuery()
            ->execute();

        return $result instanceof ApplicationDocument ? $result : null;
    }

    private function transitionOwnedClaim(ApplicationId $id, EnrichmentAttemptId $attemptId, EnrichmentStatus $target): bool
    {
        $result = $this->baseUpdate($id, EnrichmentStatus::PROCESSING, $attemptId)
            ->field('enrichmentStatus')->set($target->value)
            ->field('processingStartedAt')->set(null)
            ->field('processingAttemptId')->set(null)
            ->getQuery()
            ->execute();

        $this->documentManager->clear();

        return $result instanceof UpdateResult && 1 === $result->getModifiedCount();
    }

    private function baseUpdate(ApplicationId $id, EnrichmentStatus $status, ?EnrichmentAttemptId $attemptId): Builder
    {
        $builder = $this->documentManager->createQueryBuilder(ApplicationDocument::class)
            ->updateOne()
            ->field('id')->equals($id->value)
            ->field('enrichmentStatus')->equals($status->value);

        $builder->field('processingAttemptId')->equals($attemptId?->value);

        return $builder;
    }

    private function findDocument(ApplicationId $id): ?ApplicationDocument
    {
        $this->documentManager->clear();
        $document = $this->documentManager->find(ApplicationDocument::class, $id->value);

        return $document instanceof ApplicationDocument ? $document : null;
    }

    private function recoveryCandidate(ApplicationDocument $document): RecoveryCandidate
    {
        return new RecoveryCandidate(
            ApplicationId::fromString($document->id),
            EnrichmentStatus::from($document->enrichmentStatus),
            isset($document->processingStartedAt) ? $document->processingStartedAt : null,
            isset($document->processingAttemptId)
                ? new EnrichmentAttemptId($document->processingAttemptId)
                : null,
        );
    }

    private function assertUtc(\DateTimeImmutable $timestamp): void
    {
        if (0 !== $timestamp->getOffset()) {
            throw new \InvalidArgumentException('Enrichment persistence timestamps must represent UTC.');
        }
    }
}
