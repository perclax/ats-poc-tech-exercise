<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use Doctrine\ODM\MongoDB\DocumentManager;

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
}
