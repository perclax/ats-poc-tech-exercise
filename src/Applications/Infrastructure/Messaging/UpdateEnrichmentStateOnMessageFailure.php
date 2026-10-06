<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Port\ApplicationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final readonly class UpdateEnrichmentStateOnMessageFailure implements EventSubscriberInterface
{
    public function __construct(
        private ApplicationRepository $applications,
        private LoggerInterface $logger,
    ) {
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof EnrichApplication) {
            return;
        }

        if ($event->willRetry()) {
            $this->applications->releaseEnrichment($message->applicationId);
        } else {
            $this->applications->failEnrichment($message->applicationId);
        }

        $this->logger->warning('Enrichment handling failed.', [
            'application_id' => $message->applicationId->value,
            'outcome' => $event->willRetry() ? 'returned_to_pending' : 'marked_failed',
            'exception_class' => $event->getThrowable()::class,
        ]);
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', 0]];
    }
}
