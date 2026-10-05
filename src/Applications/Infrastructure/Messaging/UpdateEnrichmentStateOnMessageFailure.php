<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final readonly class UpdateEnrichmentStateOnMessageFailure implements EventSubscriberInterface
{
    public function __construct(
        private ApplicationEnrichmentRepository $applications,
        private LoggerInterface $logger,
    ) {
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ('enrichment_async' !== $event->getReceiverName() || !$message instanceof EnrichApplication) {
            return;
        }

        $stamp = $event->getEnvelope()->last(EnrichmentAttemptStamp::class);
        if (!$stamp instanceof EnrichmentAttemptStamp) {
            throw new \LogicException('A failed enrichment message must contain its transported attempt stamp.');
        }

        $attemptId = new EnrichmentAttemptId($stamp->attemptId);
        $changed = $event->willRetry()
            ? $this->applications->returnOwnedClaimToPending($message->applicationId, $attemptId)
            : $this->applications->markOwnedClaimFailed($message->applicationId, $attemptId);

        $this->logger->warning('Enrichment handling failed.', [
            'application_id' => $message->applicationId->value,
            'outcome' => $event->willRetry() ? 'returned_to_pending' : 'marked_failed',
            'owned_claim_changed' => $changed,
            'exception_class' => $event->getThrowable()::class,
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', 0]];
    }
}
