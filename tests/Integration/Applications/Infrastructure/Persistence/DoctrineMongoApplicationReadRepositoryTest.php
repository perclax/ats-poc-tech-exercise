<?php

declare(strict_types=1);

namespace App\Tests\Integration\Applications\Infrastructure\Persistence;

use App\Applications\Application\Port\ApplicationReadRepository;
use App\Applications\Application\Query\ApplicationSearchCriteria;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use App\Tests\Support\ApplicationReadFixtures;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineMongoApplicationReadRepositoryTest extends KernelTestCase
{
    use ApplicationReadFixtures;

    private ApplicationReadRepository $repository;
    private DocumentManager $documentManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(DocumentManager::class);
        self::assertInstanceOf(DocumentManager::class, $manager);
        $this->documentManager = $manager;
        $this->initializeReadFixtures($manager);
        $repository = self::getContainer()->get(ApplicationReadRepository::class);
        self::assertInstanceOf(ApplicationReadRepository::class, $repository);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        $this->cleanReadFixtures();
        parent::tearDown();
    }

    public function testNewestFirstOrderingUsesDescendingIdForTies(): void
    {
        $older = $this->insertApplication(['appliedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-04T10:00:00Z'))]);
        $first = $this->insertApplication();
        $second = $this->insertApplication();
        $tied = [$first['_id'], $second['_id']];
        rsort($tied);
        self::assertSame([...$tied, $older['_id']], array_column($this->repository->search(new ApplicationSearchCriteria($this->correlation)), 'id'));
    }

    public function testEveryFilterAndTheirCombinations(): void
    {
        $backend = $this->insertApplication();
        $frontend = $this->insertApplication(['jobId' => 'frontend-developer', 'enrichmentStatus' => 'failed']);
        $fullstack = $this->insertApplication(['jobId' => 'fullstack-developer', 'enrichmentStatus' => 'processing']);
        $completed = $this->insertApplication(['enrichmentStatus' => 'completed', 'enrichmentScore' => 0, 'enrichmentSummary' => 'Mock analysis: Matched 0 of 4 expected skill groups.', 'enrichedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-05T10:01:00Z'))]);
        self::assertCount(4, $this->repository->search(new ApplicationSearchCriteria($this->correlation, applicationStatus: ApplicationStatus::RECEIVED)));
        foreach (['backend-developer' => 2, 'frontend-developer' => 1, 'fullstack-developer' => 1] as $job => $count) {
            self::assertCount($count, $this->repository->search(new ApplicationSearchCriteria($this->correlation, new JobId($job))));
        }
        foreach (['pending' => $backend, 'failed' => $frontend, 'processing' => $fullstack, 'completed' => $completed] as $state => $document) {
            $items = $this->repository->search(new ApplicationSearchCriteria($this->correlation, enrichmentStatus: EnrichmentStatus::from($state)));
            self::assertSame([$document['_id']], array_column($items, 'id'));
            $combined = $this->repository->search(new ApplicationSearchCriteria($this->correlation, new JobId((string) $document['jobId']), ApplicationStatus::RECEIVED, EnrichmentStatus::from($state)));
            self::assertSame([$document['_id']], array_column($combined, 'id'));
        }
        self::assertSame([], $this->repository->search(new ApplicationSearchCriteria($this->correlation, new JobId('frontend-developer'), enrichmentStatus: EnrichmentStatus::PENDING)));
        self::assertSame([], $this->repository->search(new ApplicationSearchCriteria('missing-'.$this->correlation)));
        self::assertCount(4, $this->repository->search(new ApplicationSearchCriteria($this->correlation.'@EXAMPLE.TEST')));
    }

    #[DataProvider('literalSearches')]
    public function testSearchIsUnicodeAwareAndLiteral(string $stored, string $search): void
    {
        $match = $this->insertApplication(['candidateFullName' => $stored.' '.$this->correlation]);
        $this->insertApplication(['candidateFullName' => 'Other '.$this->correlation, 'cvText' => $search, 'notes' => $search]);
        self::assertSame([$match['_id']], array_column($this->repository->search(new ApplicationSearchCriteria($search.' '.$this->correlation)), 'id'));
        self::assertSame([$match['_id']], array_column($this->repository->search(new ApplicationSearchCriteria($search)), 'id'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function literalSearches(): iterable
    {
        yield 'Unicode' => ['Álvaro', 'álvaro'];
        yield 'metacharacters' => ['.*+?^${}()|[]', '.*+?^${}()|[]'];
        yield 'backslashes' => ['C:\\Candidates\\Demo', 'c:\\candidates\\demo'];
        yield 'flags' => ['(?i).* /iu', '(?i).* /iu'];
        yield 'internal tab' => ["Platform\tEngineer", "platform\tengineer"];
    }

    #[DataProvider('invalidSearches')]
    public function testInvalidSearchIsRejected(string $search): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->search(new ApplicationSearchCriteria($search));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSearches(): iterable
    {
        yield 'invalid UTF-8' => ["\xFF"];
        yield 'embedded NUL' => ["a\0b"];
        yield 'too long' => [str_repeat('á', 255)];
    }

    public function testDetailProjectsStoredFieldsAndZero(): void
    {
        $document = $this->insertApplication([
            'candidatePhone' => '+34 555 0101', 'notes' => "Notes\nSecond line", 'cvText' => "Original <script>CV</script>\nSecond line",
            'enrichmentStatus' => 'completed', 'enrichmentSummary' => 'Mock analysis: Matched 0 of 4 expected skill groups.', 'enrichmentScore' => 0,
            'enrichedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-05T10:01:00Z')),
        ]);
        $detail = $this->repository->findDetail(ApplicationId::fromString((string) $document['_id']));
        self::assertNotNull($detail);
        self::assertSame($document['_id'], $detail->id);
        self::assertSame($document['candidateFullName'], $detail->candidateName);
        self::assertSame($document['candidateEmail'], $detail->candidateEmail);
        self::assertSame($document['candidatePhone'], $detail->candidatePhone);
        self::assertSame($document['notes'], $detail->notes);
        self::assertSame($document['cvText'], $detail->cvText);
        self::assertSame('backend-developer', $detail->jobId);
        self::assertSame('Backend Developer', $detail->jobTitle);
        self::assertSame('Build reliable backend services with PHP, Symfony, databases, and REST APIs.', $detail->jobDescription);
        self::assertSame(ApplicationStatus::RECEIVED, $detail->applicationStatus);
        self::assertSame(EnrichmentStatus::COMPLETED, $detail->enrichmentStatus);
        self::assertSame($document['enrichmentSummary'], $detail->summary);
        self::assertSame(0, $detail->score);
        self::assertSame('2026-10-05T10:00:00+00:00', $detail->appliedAt->format('c'));
        self::assertSame('2026-10-05T10:01:00+00:00', $detail->enrichedAt?->format('c'));
        $item = $this->repository->search(new ApplicationSearchCriteria($this->correlation))[0];
        self::assertSame(0, $item->score);
    }

    public function testLegacyDocumentsNullableFieldsAndRemovedJobs(): void
    {
        $document = $this->insertApplication(['jobId' => 'retired-job']);
        $this->collection->updateOne(['_id' => $document['_id']], ['$unset' => ['candidatePhone' => '', 'notes' => '', 'enrichmentSummary' => '', 'enrichmentScore' => '', 'enrichedAt' => '']]);
        $detail = $this->repository->findDetail(ApplicationId::fromString((string) $document['_id']));
        self::assertNotNull($detail);
        self::assertNull($detail->candidatePhone);
        self::assertNull($detail->notes);
        self::assertNull($detail->summary);
        self::assertNull($detail->score);
        self::assertNull($detail->enrichedAt);
        self::assertSame('Unavailable job (retired-job)', $detail->jobTitle);
        self::assertSame('This job is no longer available in the catalogue.', $detail->jobDescription);
        self::assertNull($this->repository->findDetail(ApplicationId::fromString('018f47a2-7b3c-7def-8123-000000000000')));
    }

    public function testExactly100And101MatchesReturnOnlyTheNewest100(): void
    {
        $documents = [];
        for ($index = 0; $index < 100; ++$index) {
            $documents[] = $this->insertApplication(['appliedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-05T10:'.\sprintf('%02d', intdiv($index, 2)).':00Z'))]);
        }
        $criteria = new ApplicationSearchCriteria($this->correlation);
        usort($documents, static fn (array $left, array $right): int => [(string) $right['appliedAt'], $right['_id']] <=> [(string) $left['appliedAt'], $left['_id']]);
        self::assertSame(array_column($documents, '_id'), array_column($this->repository->search($criteria), 'id'));
        $documents[] = $this->insertApplication(['appliedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-06T10:00:00Z'))]);
        usort($documents, static fn (array $left, array $right): int => [(string) $right['appliedAt'], $right['_id']] <=> [(string) $left['appliedAt'], $left['_id']]);
        self::assertSame(\array_slice(array_column($documents, '_id'), 0, 100), array_column($this->repository->search($criteria), 'id'));
    }

    public function testPhysicalIndexesAreCorrectAndReconciliationIsRepeatable(): void
    {
        $schema = $this->documentManager->getSchemaManager();
        $schema->updateDocumentIndexes(ApplicationDocument::class);
        $schema->updateDocumentIndexes(ApplicationDocument::class);
        $indexes = [];
        foreach ($this->collection->listIndexes() as $index) {
            $indexes[$index->getName()] = $index->getKey();
        }
        self::assertSame(['appliedAt' => -1, '_id' => -1], $indexes['applications_newest']);
        self::assertSame(['jobId' => 1, 'appliedAt' => -1, '_id' => -1], $indexes['applications_job_newest']);
        self::assertSame(['enrichmentStatus' => 1, 'appliedAt' => -1, '_id' => -1], $indexes['applications_enrichment_status_newest']);
        self::assertArrayNotHasKey('applications_application_status_newest', $indexes);
    }
}
