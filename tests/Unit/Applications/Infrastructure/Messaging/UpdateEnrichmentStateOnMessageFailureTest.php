<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Infrastructure\Messaging\UpdateEnrichmentStateOnMessageFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;

final class UpdateEnrichmentStateOnMessageFailureTest extends TestCase
{
    private const string ID = '018f47a2-7b3c-7def-8123-123456789abc';

    public function testRetryableFailureReturnsTheApplicationToPending(): void
    {
        $repository = new TransitionRecordingRepository();
        $failure = new \RuntimeException('controlled failure');
        $event = new WorkerMessageFailedEvent($this->envelope(), 'enrichment_async', $failure);
        $event->setForRetry();

        (new UpdateEnrichmentStateOnMessageFailure($repository, new NullLogger()))->onMessageFailed($event);

        self::assertSame([[self::ID, 'pending']], $repository->transitions);
        self::assertSame($failure, $event->getThrowable());
    }

    public function testExhaustedFailureMarksTheApplicationFailed(): void
    {
        $repository = new TransitionRecordingRepository();
        $event = new WorkerMessageFailedEvent($this->envelope(), 'enrichment_async', new \RuntimeException('failed'));

        (new UpdateEnrichmentStateOnMessageFailure($repository, new NullLogger()))->onMessageFailed($event);

        self::assertSame([[self::ID, 'failed']], $repository->transitions);
    }

    public function testSubscriberRunsAfterRetryDecisionAndBeforeFailureTransport(): void
    {
        $retry = SendFailedMessageForRetryListener::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];
        $state = UpdateEnrichmentStateOnMessageFailure::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];
        $failure = SendFailedMessageToFailureTransportListener::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];

        self::assertGreaterThan($state, $retry);
        self::assertGreaterThan($failure, $state);
    }

    private function envelope(): Envelope
    {
        return new Envelope(new EnrichApplication(ApplicationId::fromString(self::ID)));
    }
}

final class TransitionRecordingRepository implements ApplicationRepository
{
    /** @var list<array{string, string}> */
    public array $transitions = [];

    public function find(ApplicationId $id): ?Application
    {
        throw new \LogicException('Not used.');
    }

    public function save(Application $application): void
    {
        throw new \LogicException('Not used.');
    }

    public function claimForEnrichment(ApplicationId $id): ?Application
    {
        throw new \LogicException('Not used.');
    }

    public function completeEnrichment(Application $application): void
    {
        throw new \LogicException('Not used.');
    }

    public function releaseEnrichment(ApplicationId $id): void
    {
        $this->transitions[] = [$id->value, 'pending'];
    }

    public function failEnrichment(ApplicationId $id): void
    {
        $this->transitions[] = [$id->value, 'failed'];
    }
}
