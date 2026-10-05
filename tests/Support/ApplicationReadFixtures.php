<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

trait ApplicationReadFixtures
{
    private Collection $collection;
    /** @var list<string> */
    private array $fixtureIds = [];
    private string $correlation;

    private function initializeReadFixtures(DocumentManager $documentManager): void
    {
        self::assertSame('ats_test', $documentManager->getConfiguration()->getDefaultDB(), 'Read fixtures must use the isolated test database.');
        $this->collection = $documentManager->getDocumentCollection(ApplicationDocument::class);
        $this->correlation = 'read-'.bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function insertApplication(array $overrides = []): array
    {
        $id = \sprintf('018f47a2-7b3c-7def-8123-%012x', random_int(1, 0xFFFFFFFFFFFF));
        $document = array_replace([
            '_id' => $id,
            'candidateFullName' => $this->correlation.' Candidate',
            'candidateEmail' => $this->correlation.'@example.test',
            'candidatePhone' => null,
            'jobId' => 'backend-developer',
            'notes' => null,
            'cvText' => "PHP\nSymfony",
            'applicationStatus' => 'received',
            'enrichmentStatus' => 'pending',
            'enrichmentSummary' => null,
            'enrichmentScore' => null,
            'appliedAt' => new UTCDateTime(new \DateTimeImmutable('2026-10-05T10:00:00Z')),
            'enrichedAt' => null,
        ], $overrides);
        $this->fixtureIds[] = $id;
        $this->collection->insertOne($document);

        return $document;
    }

    private function cleanReadFixtures(): void
    {
        if (isset($this->collection) && [] !== $this->fixtureIds) {
            $this->collection->deleteMany(['_id' => ['$in' => $this->fixtureIds]]);
        }
    }
}
