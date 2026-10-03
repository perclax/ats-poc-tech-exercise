<?php

declare(strict_types=1);

namespace App\Tests\Integration\Applications\Infrastructure\Persistence;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Tests\Support\MongoDbTestCase;

final class DoctrineMongoApplicationRepositoryTest extends MongoDbTestCase
{
    private ApplicationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $repository = self::getContainer()->get(ApplicationRepository::class);
        self::assertInstanceOf(ApplicationRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testItRoundTripsEveryInitialApplicationFieldIncludingNulls(): void
    {
        $id = ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');
        $application = new Application(
            $id,
            new Candidate('Ada Lovelace', new EmailAddress('ada@example.test'), null),
            new JobId('backend-developer'),
            null,
            "PHP\nSymfony",
            new \DateTimeImmutable('2026-10-03T10:20:30+00:00'),
        );

        $this->repository->save($application);
        $this->documentManager->clear();
        $stored = $this->repository->find($id);

        self::assertNotNull($stored);
        self::assertSame($id->value, $stored->id()->value);
        self::assertSame('Ada Lovelace', $stored->candidate()->fullName);
        self::assertSame('ada@example.test', $stored->candidate()->email->value);
        self::assertNull($stored->candidate()->phone);
        self::assertSame('backend-developer', $stored->jobId()->value);
        self::assertNull($stored->notes());
        self::assertSame("PHP\nSymfony", $stored->cvText());
        self::assertSame(ApplicationStatus::RECEIVED, $stored->status());
        self::assertSame(EnrichmentStatus::PENDING, $stored->enrichmentStatus());
        self::assertNull($stored->enrichmentResult());
        self::assertNull($stored->enrichedAt());
        self::assertSame('2026-10-03T10:20:30+00:00', $stored->appliedAt()->format('c'));
    }

    public function testRepeatedEmailAndJobApplicationsAreAllowed(): void
    {
        $first = $this->application('018f47a2-7b3c-7def-8123-123456789abc');
        $second = $this->application('018f47a2-7b3c-7def-8123-123456789abd');

        $this->repository->save($first);
        $this->repository->save($second);
        $this->documentManager->clear();

        self::assertNotNull($this->repository->find($first->id()));
        self::assertNotNull($this->repository->find($second->id()));
    }

    private function application(string $id): Application
    {
        return new Application(
            ApplicationId::fromString($id),
            new Candidate('Ada Lovelace', new EmailAddress('same@example.test'), null),
            new JobId('backend-developer'),
            null,
            'PHP',
            new \DateTimeImmutable('2026-10-03T10:20:30+00:00'),
        );
    }
}
