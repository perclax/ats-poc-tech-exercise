<?php

declare(strict_types=1);

namespace App\Tests\Integration\Applications\Infrastructure\Persistence;

use App\Applications\Application\Enrichment\EnrichmentAttemptId;
use App\Applications\Application\Enrichment\EnrichmentClaimOutcome;
use App\Applications\Application\Enrichment\EnrichmentCompletionOutcome;
use App\Applications\Application\Port\ApplicationEnrichmentRepository;
use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use App\Tests\Support\MongoDbTestCase;

final class DoctrineMongoApplicationEnrichmentRepositoryTest extends MongoDbTestCase
{
    private ApplicationEnrichmentRepository $enrichments;
    private ApplicationRepository $applications;

    protected function setUp(): void
    {
        parent::setUp();
        $enrichments = self::getContainer()->get(ApplicationEnrichmentRepository::class);
        $applications = self::getContainer()->get(ApplicationRepository::class);
        self::assertInstanceOf(ApplicationEnrichmentRepository::class, $enrichments);
        self::assertInstanceOf(ApplicationRepository::class, $applications);
        $this->enrichments = $enrichments;
        $this->applications = $applications;
    }

    public function testOnlyOneDifferentAttemptClaimsPendingAndSameAttemptResumes(): void
    {
        $id = $this->saveApplication();
        $firstAttempt = $this->attempt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $otherAttempt = $this->attempt('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $first = $this->enrichments->claim($id, $firstAttempt, $this->time('10:01:00'));
        $competing = $this->enrichments->claim($id, $otherAttempt, $this->time('10:01:01'));
        $resumed = $this->enrichments->claim($id, $firstAttempt, $this->time('10:01:02'));

        self::assertSame(EnrichmentClaimOutcome::CLAIMED, $first->outcome);
        self::assertSame(EnrichmentClaimOutcome::COMPETING, $competing->outcome);
        self::assertSame(EnrichmentClaimOutcome::RESUMED, $resumed->outcome);
        self::assertNotNull($resumed->claim);
        self::assertSame(EnrichmentStatus::PROCESSING, $resumed->claim->application->enrichmentStatus());
        $document = $this->document($id);
        self::assertSame($firstAttempt->value, $document->processingAttemptId);
        self::assertSame('2026-10-03T10:01:02+00:00', $document->processingStartedAt?->format('c'));
    }

    public function testConcurrentSameAttemptCompletionIsConditionalAndIdempotent(): void
    {
        $id = $this->saveApplication();
        $attempt = $this->attempt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $first = $this->enrichments->claim($id, $attempt, $this->time('10:01:00'));
        $second = $this->enrichments->claim($id, $attempt, $this->time('10:01:01'));
        self::assertNotNull($first->claim);
        self::assertNotNull($second->claim);

        $result = new EnrichmentResult('Mock analysis: Matched 1 of 4 expected skill groups: PHP.', 25);
        $first->claim->application->completeEnrichment($result, $this->time('10:02:00'));
        $second->claim->application->completeEnrichment($result, $this->time('10:02:01'));

        self::assertSame(EnrichmentCompletionOutcome::COMPLETED, $this->enrichments->complete($first->claim));
        self::assertSame(EnrichmentCompletionOutcome::ALREADY_COMPLETED, $this->enrichments->complete($second->claim));
        $stored = $this->applications->find($id);
        self::assertNotNull($stored);
        self::assertSame(EnrichmentStatus::COMPLETED, $stored->enrichmentStatus());
        self::assertSame(25, $stored->enrichmentResult()?->score);
        self::assertSame('2026-10-03T10:02:00+00:00', $stored->enrichedAt()?->format('c'));
        $document = $this->document($id);
        self::assertNull($document->processingAttemptId);
        self::assertNull($document->processingStartedAt);
    }

    public function testOnlyTheOwnerCanReleaseOrFailAClaim(): void
    {
        $id = $this->saveApplication();
        $owner = $this->attempt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $other = $this->attempt('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $this->enrichments->claim($id, $owner, $this->time('10:01:00'));

        self::assertFalse($this->enrichments->returnOwnedClaimToPending($id, $other));
        self::assertFalse($this->enrichments->markOwnedClaimFailed($id, $other));
        self::assertTrue($this->enrichments->returnOwnedClaimToPending($id, $owner));
        self::assertSame(EnrichmentStatus::PENDING, $this->applications->find($id)?->enrichmentStatus());

        $this->enrichments->claim($id, $owner, $this->time('10:02:00'));
        self::assertTrue($this->enrichments->markOwnedClaimFailed($id, $owner));
        self::assertSame(EnrichmentStatus::FAILED, $this->applications->find($id)?->enrichmentStatus());
    }

    public function testRecoveryFindsAndResetsOnlyStaleProcessingBeforePending(): void
    {
        $staleId = $this->saveApplication('018f47a2-7b3c-7def-8123-123456789aba');
        $freshId = $this->saveApplication('018f47a2-7b3c-7def-8123-123456789abb');
        $pendingId = $this->saveApplication('018f47a2-7b3c-7def-8123-123456789abc');
        $attempt = $this->attempt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $this->enrichments->claim($staleId, $attempt, $this->time('10:00:00'));
        $this->enrichments->claim($freshId, $attempt, $this->time('10:20:00'));

        $candidates = $this->enrichments->recoveryCandidates($this->time('10:15:00'), 2);

        self::assertSame([$staleId->value, $pendingId->value], array_map(
            static fn ($candidate): string => $candidate->applicationId->value,
            $candidates,
        ));
        self::assertTrue($this->enrichments->resetStaleProcessing($candidates[0], $this->time('10:15:00')));
        self::assertSame(EnrichmentStatus::PENDING, $this->applications->find($staleId)?->enrichmentStatus());
        self::assertSame(EnrichmentStatus::PROCESSING, $this->applications->find($freshId)?->enrichmentStatus());
    }

    public function testLegacyProcessingWithoutLeaseMetadataIsRecoverable(): void
    {
        $id = $this->saveApplication();
        $this->documentManager->getDocumentCollection(ApplicationDocument::class)->updateOne(
            ['_id' => $id->value],
            [
                '$set' => ['enrichmentStatus' => EnrichmentStatus::PROCESSING->value],
                '$unset' => ['processingStartedAt' => true, 'processingAttemptId' => true],
            ],
        );
        $this->documentManager->clear();

        $candidates = $this->enrichments->recoveryCandidates($this->time('10:15:00'), 10);

        self::assertCount(1, $candidates);
        self::assertSame($id->value, $candidates[0]->applicationId->value);
        self::assertNull($candidates[0]->processingStartedAt);
        self::assertNull($candidates[0]->attemptId);
        self::assertTrue($this->enrichments->resetStaleProcessing($candidates[0], $this->time('10:15:00')));
        self::assertSame(EnrichmentStatus::PENDING, $this->applications->find($id)?->enrichmentStatus());
    }

    public function testRecoveryNeverSelectsCompletedOrFailedApplications(): void
    {
        $completedId = $this->saveApplication('018f47a2-7b3c-7def-8123-123456789aba');
        $failedId = $this->saveApplication('018f47a2-7b3c-7def-8123-123456789abb');
        $attempt = $this->attempt('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $completed = $this->enrichments->claim($completedId, $attempt, $this->time('10:01:00'));
        self::assertNotNull($completed->claim);
        $completed->claim->application->completeEnrichment(new EnrichmentResult('Summary', 0), $this->time('10:02:00'));
        self::assertSame(EnrichmentCompletionOutcome::COMPLETED, $this->enrichments->complete($completed->claim));
        $this->enrichments->claim($failedId, $attempt, $this->time('10:01:00'));
        self::assertTrue($this->enrichments->markOwnedClaimFailed($failedId, $attempt));

        self::assertSame([], $this->enrichments->recoveryCandidates($this->time('10:15:00'), 10));
    }

    private function saveApplication(string $value = '018f47a2-7b3c-7def-8123-123456789abc'): ApplicationId
    {
        $id = ApplicationId::fromString($value);
        $this->applications->save(new Application(
            $id,
            new Candidate('Test Candidate', new EmailAddress('test@example.test'), null),
            new JobId('backend-developer'),
            null,
            'PHP',
            $this->time('10:00:00'),
        ));
        $this->documentManager->clear();

        return $id;
    }

    private function document(ApplicationId $id): ApplicationDocument
    {
        $this->documentManager->clear();
        $document = $this->documentManager->find(ApplicationDocument::class, $id->value);
        self::assertInstanceOf(ApplicationDocument::class, $document);

        return $document;
    }

    private function attempt(string $value): EnrichmentAttemptId
    {
        return new EnrichmentAttemptId($value);
    }

    private function time(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-03T'.$time.'+00:00');
    }
}
