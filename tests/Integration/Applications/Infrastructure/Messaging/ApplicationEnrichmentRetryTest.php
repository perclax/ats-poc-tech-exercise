<?php

declare(strict_types=1);

namespace App\Tests\Integration\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\CvEnricher;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Messaging\EnrichmentAttemptStamp;
use App\Applications\Infrastructure\Messaging\MessengerEnrichmentDispatcher;
use App\Tests\Support\MongoDbTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ApplicationEnrichmentRetryTest extends MongoDbTestCase
{
    public function testFailureRetriesThreeTimesPreservingAttemptThenFailsTerminally(): void
    {
        $enricher = new AlwaysFailingCvEnricher();
        self::getContainer()->set(CvEnricher::class, $enricher);
        $applications = self::getContainer()->get(ApplicationRepository::class);
        self::assertInstanceOf(ApplicationRepository::class, $applications);
        $id = ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc');
        $applications->save(new Application(
            $id,
            new Candidate('Private Candidate', new EmailAddress('private@example.test'), null),
            new JobId('backend-developer'),
            null,
            'Private PHP CV',
            new \DateTimeImmutable('2026-10-03T10:00:00+00:00'),
        ));

        $transport = $this->transport('enrichment_async');
        $failed = $this->transport('failed');
        $transport->reset();
        $failed->reset();
        $dispatcher = self::getContainer()->get(MessengerEnrichmentDispatcher::class);
        self::assertInstanceOf(MessengerEnrichmentDispatcher::class, $dispatcher);
        $dispatcher->dispatch(new EnrichApplication($id));

        $attemptId = null;
        for ($delivery = 0; $delivery < 4; ++$delivery) {
            $sent = $transport->getSent();
            self::assertCount(1, $sent);
            $envelope = $sent[0];
            $stamp = $envelope->last(EnrichmentAttemptStamp::class);
            self::assertInstanceOf(EnrichmentAttemptStamp::class, $stamp);
            $attemptId ??= $stamp->attemptId;
            self::assertSame($attemptId, $stamp->attemptId);
            self::assertSame($delivery, RedeliveryStamp::getRetryCountFromEnvelope($envelope));
            $transport->reset();

            $received = $envelope->with(new ReceivedStamp('enrichment_async'));
            $failure = $this->handleFailure($received);
            $event = new WorkerMessageFailedEvent($received, 'enrichment_async', $failure);
            $this->eventDispatcher()->dispatch($event);

            $this->documentManager->clear();
            $stored = $applications->find($id);
            self::assertNotNull($stored);
            self::assertSame($delivery < 3 ? EnrichmentStatus::PENDING : EnrichmentStatus::FAILED, $stored->enrichmentStatus());
        }

        self::assertSame(4, $enricher->calls);
        self::assertCount(0, $transport->getSent());
        self::assertCount(1, $failed->getSent());
        self::assertSame($attemptId, $failed->getSent()[0]->last(EnrichmentAttemptStamp::class)?->attemptId);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $attemptId);
        new EnrichmentAttemptId((string) $attemptId);
    }

    private function handleFailure(Envelope $envelope): HandlerFailedException
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        try {
            $bus->dispatch($envelope->getMessage(), $this->flattenStamps($envelope));
            self::fail('The controlled enricher should fail.');
        } catch (HandlerFailedException $exception) {
            return $exception;
        }
    }

    /** @return list<StampInterface> */
    private function flattenStamps(Envelope $envelope): array
    {
        $flat = [];
        foreach ($envelope->all() as $stamps) {
            foreach ($stamps as $stamp) {
                $flat[] = $stamp;
            }
        }

        return $flat;
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}

final class AlwaysFailingCvEnricher implements CvEnricher
{
    public int $calls = 0;

    public function enrich(string $cvText, Job $job): EnrichmentResult
    {
        ++$this->calls;

        throw new \RuntimeException('Controlled enrichment failure without candidate data.');
    }
}
