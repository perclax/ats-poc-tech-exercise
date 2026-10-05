<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaim;
use App\Applications\Application\Enrichment\EnrichmentClaimResult;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Enrichment\RecoveryCandidate;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Infrastructure\Messaging\AddEnrichmentHandlerArgumentsMiddleware;
use App\Applications\Infrastructure\Messaging\EnrichmentAttemptStamp;
use App\Applications\Infrastructure\Messaging\UpdateEnrichmentStateOnMessageFailure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class EnrichmentMessagingLifecycleTest extends TestCase
{
    private const string ATTEMPT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAttemptStampSurvivesSerializationAndHandlerArgumentsDoNot(): void
    {
        $serializer = new PhpSerializer();
        $envelope = new Envelope($this->message(), [
            new EnrichmentAttemptStamp(self::ATTEMPT),
            new HandlerArgumentsStamp([new EnrichmentAttemptId(self::ATTEMPT)]),
        ]);

        $decoded = $serializer->decode($serializer->encode($envelope));

        self::assertSame(self::ATTEMPT, $decoded->last(EnrichmentAttemptStamp::class)?->attemptId);
        self::assertNull($decoded->last(HandlerArgumentsStamp::class));
        self::assertCount(1, (new \ReflectionClass($decoded->getMessage()))->getProperties(\ReflectionProperty::IS_PUBLIC));
    }

    public function testMiddlewareAddsHandlerArgumentOnlyOnReceivingSide(): void
    {
        $middleware = new AddEnrichmentHandlerArgumentsMiddleware();
        $capture = new CapturingMiddleware();
        $stack = new SingleMiddlewareStack($capture);
        $outgoing = new Envelope($this->message(), [new EnrichmentAttemptStamp(self::ATTEMPT)]);

        $middleware->handle($outgoing, $stack);
        self::assertNull($capture->envelope?->last(HandlerArgumentsStamp::class));

        $incoming = $outgoing->with(new ReceivedStamp('enrichment_async'));
        $middleware->handle($incoming, $stack);
        $handlerArguments = $capture->envelope?->last(HandlerArgumentsStamp::class);
        self::assertInstanceOf(HandlerArgumentsStamp::class, $handlerArguments);
        self::assertEquals([new EnrichmentAttemptId(self::ATTEMPT)], $handlerArguments->getAdditionalArguments());
    }

    public function testMiddlewareUpgradesLegacyReceivedMessageWithATransportedAttempt(): void
    {
        $middleware = new AddEnrichmentHandlerArgumentsMiddleware();
        $capture = new CapturingMiddleware();
        $middleware->handle(
            new Envelope($this->message(), [new ReceivedStamp('enrichment_async')]),
            new SingleMiddlewareStack($capture),
        );

        $attemptStamp = $capture->envelope?->last(EnrichmentAttemptStamp::class);
        self::assertInstanceOf(EnrichmentAttemptStamp::class, $attemptStamp);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $attemptStamp->attemptId);
        $arguments = $capture->envelope?->last(HandlerArgumentsStamp::class)?->getAdditionalArguments();
        self::assertEquals([new EnrichmentAttemptId($attemptStamp->attemptId)], $arguments);
    }

    public function testFailureSubscriberReleasesOnlyOwnedRetryAndPreservesOriginalException(): void
    {
        $repository = new LifecycleRepository();
        $subscriber = new UpdateEnrichmentStateOnMessageFailure($repository, new NullLogger());
        $failure = new \LogicException('visible programming exception');
        $event = new WorkerMessageFailedEvent($this->envelope(), 'enrichment_async', $failure);
        $event->setForRetry();

        $subscriber->onMessageFailed($event);

        self::assertSame([[self::ATTEMPT, 'pending']], $repository->transitions);
        self::assertSame($failure, $event->getThrowable());
    }

    public function testFailureSubscriberMarksOnlyOwnedExhaustedAttemptFailed(): void
    {
        $repository = new LifecycleRepository();
        $subscriber = new UpdateEnrichmentStateOnMessageFailure($repository, new NullLogger());
        $event = new WorkerMessageFailedEvent($this->envelope(), 'enrichment_async', new \RuntimeException('failed'));

        $subscriber->onMessageFailed($event);

        self::assertSame([[self::ATTEMPT, 'failed']], $repository->transitions);
    }

    public function testInstalledSymfonyListenerPrioritiesBracketTheStateSubscriber(): void
    {
        $retry = SendFailedMessageForRetryListener::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];
        $state = UpdateEnrichmentStateOnMessageFailure::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];
        $failure = SendFailedMessageToFailureTransportListener::getSubscribedEvents()[WorkerMessageFailedEvent::class][1];

        self::assertSame(100, $retry);
        self::assertSame(0, $state);
        self::assertSame(-100, $failure);
        self::assertGreaterThan($state, $retry);
        self::assertGreaterThan($failure, $state);
    }

    private function envelope(): Envelope
    {
        return new Envelope($this->message(), [new EnrichmentAttemptStamp(self::ATTEMPT)]);
    }

    private function message(): EnrichApplication
    {
        return new EnrichApplication(ApplicationId::fromString('018f47a2-7b3c-7def-8123-123456789abc'));
    }
}

final class CapturingMiddleware implements MiddlewareInterface
{
    public ?Envelope $envelope = null;

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        return $this->envelope = $envelope;
    }
}

final readonly class SingleMiddlewareStack implements StackInterface
{
    public function __construct(private MiddlewareInterface $middleware)
    {
    }

    public function next(): MiddlewareInterface
    {
        return $this->middleware;
    }
}

final class LifecycleRepository implements ApplicationEnrichmentRepository
{
    /** @var list<array{string, string}> */
    public array $transitions = [];

    public function claim(ApplicationId $id, EnrichmentAttemptId $attemptId, \DateTimeImmutable $startedAt): EnrichmentClaimResult
    {
        throw new \LogicException('Not used.');
    }

    public function complete(EnrichmentClaim $claim): EnrichmentCompletionOutcome
    {
        throw new \LogicException('Not used.');
    }

    public function returnOwnedClaimToPending(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        $this->transitions[] = [$attemptId->value, 'pending'];

        return true;
    }

    public function markOwnedClaimFailed(ApplicationId $id, EnrichmentAttemptId $attemptId): bool
    {
        $this->transitions[] = [$attemptId->value, 'failed'];

        return true;
    }

    public function recoveryCandidates(\DateTimeImmutable $staleBefore, int $limit): array
    {
        return [];
    }

    public function resetStaleProcessing(RecoveryCandidate $candidate, \DateTimeImmutable $staleBefore): bool
    {
        return false;
    }
}
