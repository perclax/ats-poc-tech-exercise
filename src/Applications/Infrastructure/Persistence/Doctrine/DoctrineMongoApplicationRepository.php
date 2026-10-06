<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;

final readonly class DoctrineMongoApplicationRepository implements ApplicationRepository
{
    public function __construct(
        private DocumentManager $documentManager,
        private ApplicationDocumentMapper $mapper,
    ) {
    }

    public function find(ApplicationId $id): ?Application
    {
        $document = $this->documentManager->find(ApplicationDocument::class, $id->value);

        return $document instanceof ApplicationDocument ? $this->mapper->toDomain($document) : null;
    }

    public function save(Application $application): void
    {
        $this->documentManager->persist($this->mapper->toDocument($application));
        $this->documentManager->flush();
    }

    public function claimForEnrichment(ApplicationId $id): ?Application
    {
        $document = $this->documentManager->createQueryBuilder(ApplicationDocument::class)
            ->findAndUpdate()
            ->field('id')->equals($id->value)
            ->field('enrichmentStatus')->equals(EnrichmentStatus::PENDING->value)
            ->field('enrichmentStatus')->set(EnrichmentStatus::PROCESSING->value)
            ->returnNew()
            ->getQuery()
            ->execute();

        return $document instanceof ApplicationDocument ? $this->mapper->toDomain($document) : null;
    }

    public function completeEnrichment(Application $application): void
    {
        $result = $application->enrichmentResult();
        if (EnrichmentStatus::COMPLETED !== $application->enrichmentStatus() || null === $result) {
            throw new \LogicException('Only a completed application can be persisted as completed.');
        }

        $this->updateProcessing($application->id())
            ->field('enrichmentStatus')->set(EnrichmentStatus::COMPLETED->value)
            ->field('enrichmentSummary')->set($result->summary)
            ->field('enrichmentScore')->set($result->score)
            ->field('enrichedAt')->set($application->enrichedAt())
            ->getQuery()
            ->execute();
        $this->documentManager->clear();
    }

    public function releaseEnrichment(ApplicationId $id): void
    {
        $this->transitionProcessing($id, EnrichmentStatus::PENDING);
    }

    public function failEnrichment(ApplicationId $id): void
    {
        $this->transitionProcessing($id, EnrichmentStatus::FAILED);
    }

    private function transitionProcessing(ApplicationId $id, EnrichmentStatus $target): void
    {
        $this->updateProcessing($id)
            ->field('enrichmentStatus')->set($target->value)
            ->getQuery()
            ->execute();
        $this->documentManager->clear();
    }

    private function updateProcessing(ApplicationId $id): Builder
    {
        return $this->documentManager->createQueryBuilder(ApplicationDocument::class)
            ->updateOne()
            ->field('id')->equals($id->value)
            ->field('enrichmentStatus')->equals(EnrichmentStatus::PROCESSING->value);
    }
}
