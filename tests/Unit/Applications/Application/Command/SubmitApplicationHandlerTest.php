<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Application\Command;

use App\Applications\Application\Command\SubmitApplication;
use App\Applications\Application\Command\SubmitApplicationHandler;
use App\Applications\Application\Exception\EnrichmentDispatchFailed;
use App\Applications\Application\Exception\UnknownJob;
use App\Applications\Application\Port\ApplicationIdGenerator;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\Clock;
use App\Applications\Application\Port\EventPublisher;
use App\Applications\Application\Port\JobCatalog;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Event\ApplicationSubmitted;
use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;
use App\Applications\Domain\Job\SkillGroup;
use PHPUnit\Framework\TestCase;

final class SubmitApplicationHandlerTest extends TestCase
{
    private const string APPLICATION_ID = '018f47a2-7b3c-7def-8123-123456789abc';

    public function testItCreatesPersistsThenPublishesAnApplicationUsingControlledIdentityAndTime(): void
    {
        $operations = new OperationLog();
        $repository = new RecordingRepository($operations);
        $publisher = new RecordingPublisher($operations);
        $appliedAt = new \DateTimeImmutable('2026-10-03T10:20:30+00:00');

        $result = ($this->handler($repository, $publisher, $appliedAt))($this->command());

        self::assertSame(self::APPLICATION_ID, $result->applicationId->value);
        self::assertTrue($result->analysisQueued);
        self::assertSame(['save', 'publish'], $operations->values);
        self::assertNotNull($repository->saved);
        self::assertSame($appliedAt, $repository->saved->appliedAt());
        self::assertSame(EnrichmentStatus::PENDING, $repository->saved->enrichmentStatus());
        self::assertSame(self::APPLICATION_ID, $publisher->published?->applicationId->value);
    }

    public function testItRejectsAJobOutsideTheCatalogueBeforePersistence(): void
    {
        $operations = new OperationLog();
        $repository = new RecordingRepository($operations);
        $publisher = new RecordingPublisher($operations);
        $handler = $this->handler($repository, $publisher, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->expectException(UnknownJob::class);

        $handler(new SubmitApplication('Ada Lovelace', 'ada@example.test', null, 'unknown', null, 'PHP'));
    }

    public function testPersistenceFailurePreventsPublication(): void
    {
        $operations = new OperationLog();
        $repository = new RecordingRepository($operations, true);
        $publisher = new RecordingPublisher($operations);

        try {
            ($this->handler($repository, $publisher, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))))($this->command());
            self::fail('The persistence exception should propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('save failed', $exception->getMessage());
        }

        self::assertSame(['save'], $operations->values);
        self::assertNull($publisher->published);
    }

    public function testExpectedDispatchFailureReturnsRecoverableResultAfterOneSave(): void
    {
        $operations = new OperationLog();
        $repository = new RecordingRepository($operations);
        $publisher = new RecordingPublisher($operations, new EnrichmentDispatchFailed('transport unavailable'));

        $result = ($this->handler($repository, $publisher, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))))($this->command());

        self::assertFalse($result->analysisQueued);
        self::assertSame(['save', 'publish'], $operations->values);
        self::assertSame(1, $repository->saveCalls);
        self::assertSame(EnrichmentStatus::PENDING, $repository->saved?->enrichmentStatus());
    }

    public function testUnexpectedProgrammingExceptionPropagates(): void
    {
        $operations = new OperationLog();
        $repository = new RecordingRepository($operations);
        $publisher = new RecordingPublisher($operations, new \LogicException('broken listener'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('broken listener');

        ($this->handler($repository, $publisher, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))))($this->command());
    }

    private function command(): SubmitApplication
    {
        return new SubmitApplication('Ada Lovelace', 'ada@example.test', '+44 123', 'backend-developer', 'Notes', 'PHP and Symfony');
    }

    private function handler(
        ApplicationRepository $repository,
        EventPublisher $publisher,
        \DateTimeImmutable $now,
    ): SubmitApplicationHandler {
        return new SubmitApplicationHandler(
            $repository,
            new SingleJobCatalog(),
            new FixedApplicationIdGenerator(),
            new FixedClock($now),
            $publisher,
        );
    }
}

final class FixedApplicationIdGenerator implements ApplicationIdGenerator
{
    public function generate(): ApplicationId
    {
        return ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');
    }
}

final readonly class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

final class RecordingRepository implements ApplicationRepository
{
    public ?Application $saved = null;
    public int $saveCalls = 0;

    public function __construct(private readonly OperationLog $operations, private readonly bool $fail = false)
    {
    }

    public function find(ApplicationId $id): ?Application
    {
        return null;
    }

    public function save(Application $application): void
    {
        ++$this->saveCalls;
        $this->operations->add('save');
        if ($this->fail) {
            throw new \RuntimeException('save failed');
        }
        $this->saved = $application;
    }
}

final class RecordingPublisher implements EventPublisher
{
    public ?ApplicationSubmitted $published = null;

    public function __construct(private readonly OperationLog $operations, private readonly ?\Throwable $failure = null)
    {
    }

    public function publish(ApplicationSubmitted $event): void
    {
        $this->operations->add('publish');
        $this->published = $event;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}

final class SingleJobCatalog implements JobCatalog
{
    /** @return list<Job> */
    public function all(): array
    {
        return [new Job(new JobId('backend-developer'), 'Backend Developer', 'Backend role', [new SkillGroup('PHP', ['php'])])];
    }

    public function find(JobId $id): ?Job
    {
        return 'backend-developer' === $id->value ? $this->all()[0] : null;
    }
}

final class OperationLog
{
    /** @var list<string> */
    public array $values = [];

    public function add(string $operation): void
    {
        $this->values[] = $operation;
    }
}
