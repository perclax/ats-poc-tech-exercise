<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Messaging;

use App\Applications\Application\Command\EnrichApplication;
use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandlerArgumentsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class AddEnrichmentHandlerArgumentsMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof EnrichApplication || null === $envelope->last(ReceivedStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $attemptStamp = $envelope->last(EnrichmentAttemptStamp::class);
        if (!$attemptStamp instanceof EnrichmentAttemptStamp) {
            $attemptStamp = new EnrichmentAttemptStamp(bin2hex(random_bytes(16)));
            $envelope = $envelope->with($attemptStamp);
        }

        return $stack->next()->handle($envelope->with(new HandlerArgumentsStamp([
            new EnrichmentAttemptId($attemptStamp->attemptId),
        ])), $stack);
    }
}
