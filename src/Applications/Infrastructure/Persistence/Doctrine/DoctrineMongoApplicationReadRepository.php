<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Persistence\Doctrine;

use App\Applications\Application\Port\ApplicationReadRepository;
use App\Applications\Application\Port\JobCatalog;
use App\Applications\Application\Query\ApplicationDetail;
use App\Applications\Application\Query\ApplicationSearchCriteria;
use App\Applications\Domain\Application\ApplicationId;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\Regex;
use MongoDB\Collection;

final readonly class DoctrineMongoApplicationReadRepository implements ApplicationReadRepository
{
    public const int MAX_RESULTS = 100;
    private const array LIST_PROJECTION = [
        '_id' => 1,
        'candidateFullName' => 1,
        'candidateEmail' => 1,
        'jobId' => 1,
        'applicationStatus' => 1,
        'enrichmentStatus' => 1,
        'enrichmentScore' => 1,
        'appliedAt' => 1,
    ];
    private const array DETAIL_PROJECTION = self::LIST_PROJECTION + [
        'candidatePhone' => 1,
        'notes' => 1,
        'cvText' => 1,
        'enrichmentSummary' => 1,
        'enrichedAt' => 1,
    ];

    public function __construct(
        private DocumentManager $documentManager,
        private ApplicationReadDocumentMapper $mapper,
        private JobCatalog $jobs,
    ) {
    }

    public function search(ApplicationSearchCriteria $criteria): array
    {
        $filter = [];
        if (null !== $criteria->jobId) {
            $filter['jobId'] = $criteria->jobId->value;
        }
        if (null !== $criteria->applicationStatus) {
            $filter['applicationStatus'] = $criteria->applicationStatus->value;
        }
        if (null !== $criteria->enrichmentStatus) {
            $filter['enrichmentStatus'] = $criteria->enrichmentStatus->value;
        }
        if (null !== $criteria->search && '' !== $criteria->search) {
            if (!mb_check_encoding($criteria->search, 'UTF-8') || str_contains($criteria->search, "\0") || mb_strlen($criteria->search, 'UTF-8') > 254) {
                throw new \InvalidArgumentException('Search must be valid UTF-8, contain no NUL, and be at most 254 characters.');
            }
            $literal = new Regex(preg_quote($criteria->search), 'iu');
            $filter['$or'] = [['candidateFullName' => $literal], ['candidateEmail' => $literal]];
        }

        $documents = $this->collection()->find($filter, [
            'projection' => self::LIST_PROJECTION,
            'sort' => ['appliedAt' => -1, '_id' => -1],
            'limit' => self::MAX_RESULTS,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
        $jobs = $this->catalogue();
        $items = [];
        foreach ($documents as $document) {
            $items[] = $this->mapper->toListItem($this->documentArray($document), $jobs);
        }

        return $items;
    }

    public function findDetail(ApplicationId $id): ?ApplicationDetail
    {
        $document = $this->collection()->findOne(['_id' => $id->value], [
            'projection' => self::DETAIL_PROJECTION,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);

        return null === $document ? null : $this->mapper->toDetail($this->documentArray($document), $this->catalogue());
    }

    private function collection(): Collection
    {
        return $this->documentManager->getDocumentCollection(ApplicationDocument::class);
    }

    /** @return array<string, array{title: string, description: string}> */
    private function catalogue(): array
    {
        $jobs = [];
        foreach ($this->jobs->all() as $job) {
            $jobs[$job->id->value] = ['title' => $job->title, 'description' => $job->description];
        }

        return $jobs;
    }

    /** @return array<string, mixed> */
    private function documentArray(mixed $document): array
    {
        if (!\is_array($document)) {
            throw new \UnexpectedValueException('MongoDB did not return an application read document.');
        }
        foreach (array_keys($document) as $key) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('MongoDB returned an invalid application read field.');
            }
        }

        return $document;
    }
}
