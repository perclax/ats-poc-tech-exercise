<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Domain\Application;

use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Enrichment\InvalidEnrichmentTransition;
use App\Applications\Domain\Job\JobId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(InvalidEnrichmentTransition::class)]
final class ApplicationTest extends TestCase
{
    private const string CV_TEXT = 'PHP and Symfony experience.';

    public function testItCreatesAReceivedPendingApplicationInMemory(): void
    {
        $appliedAt = new \DateTimeImmutable('2026-01-02T10:00:00+00:00');
        $application = $this->application(appliedAt: $appliedAt, notes: '', cvText: "  Original CV\n");

        self::assertSame(ApplicationStatus::RECEIVED, $application->status());
        self::assertSame(EnrichmentStatus::PENDING, $application->enrichmentStatus());
        self::assertSame($appliedAt, $application->appliedAt());
        self::assertNull($application->notes());
        self::assertSame("  Original CV\n", $application->cvText());
        self::assertNull($application->enrichmentResult());
        self::assertNull($application->enrichedAt());
    }

    public function testItCompletesEnrichmentAtomicallyIncludingAZeroScore(): void
    {
        $application = $this->application();
        $application->startEnrichment();
        $result = new EnrichmentResult('No expected skills detected.', 0);
        $enrichedAt = new \DateTimeImmutable('2026-01-02T10:05:00Z');

        $application->completeEnrichment($result, $enrichedAt);

        self::assertSame(EnrichmentStatus::COMPLETED, $application->enrichmentStatus());
        self::assertSame($result, $application->enrichmentResult());
        self::assertSame(0, $result->score);
        self::assertSame($enrichedAt, $application->enrichedAt());
    }

    public function testItCanReturnAProcessingAttemptToPending(): void
    {
        $application = $this->application();
        $application->startEnrichment();
        $application->returnEnrichmentToPending();

        self::assertSame(EnrichmentStatus::PENDING, $application->enrichmentStatus());
        self::assertNull($application->enrichmentResult());
        self::assertNull($application->enrichedAt());
    }

    public function testItCanFailOnlyWhileProcessing(): void
    {
        $application = $this->application();
        $application->startEnrichment();
        $application->failEnrichment();

        self::assertSame(EnrichmentStatus::FAILED, $application->enrichmentStatus());
        self::assertNull($application->enrichmentResult());
        self::assertNull($application->enrichedAt());
    }

    #[DataProvider('invalidTransitionActions')]
    public function testItRejectsInvalidTransitions(string $action): void
    {
        $application = $this->application();
        $this->expectException(InvalidEnrichmentTransition::class);

        match ($action) {
            'pending-to-pending' => $application->returnEnrichmentToPending(),
            'pending-to-completed' => $application->completeEnrichment(new EnrichmentResult('Summary', 50), new \DateTimeImmutable('2026-01-02T10:05:00Z')),
            'pending-to-failed' => $application->failEnrichment(),
            'processing-to-processing' => (static function () use ($application): void {
                $application->startEnrichment();
                $application->startEnrichment();
            })(),
            'completed-to-processing' => (static function () use ($application): void {
                $application->startEnrichment();
                $application->completeEnrichment(new EnrichmentResult('Summary', 50), new \DateTimeImmutable('2026-01-02T10:05:00Z'));
                $application->startEnrichment();
            })(),
            'failed-to-processing' => (static function () use ($application): void {
                $application->startEnrichment();
                $application->failEnrichment();
                $application->startEnrichment();
            })(),
            default => throw new \LogicException(\sprintf('Unknown transition action "%s".', $action)),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTransitionActions(): iterable
    {
        yield 'pending cannot return to pending' => ['pending-to-pending'];
        yield 'pending cannot complete' => ['pending-to-completed'];
        yield 'pending cannot fail' => ['pending-to-failed'];
        yield 'processing cannot start again' => ['processing-to-processing'];
        yield 'completed is terminal' => ['completed-to-processing'];
        yield 'failed is terminal' => ['failed-to-processing'];
    }

    public function testItRejectsANonUtcApplicationTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->application(appliedAt: new \DateTimeImmutable('2026-01-02T11:00:00+01:00'));
    }

    public function testItRejectsANonUtcEnrichmentTimestampWithoutChangingState(): void
    {
        $application = $this->application();
        $application->startEnrichment();

        try {
            $application->completeEnrichment(new EnrichmentResult('Summary', 50), new \DateTimeImmutable('2026-01-02T11:05:00+01:00'));
            self::fail('Expected a non-UTC timestamp to be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(EnrichmentStatus::PROCESSING, $application->enrichmentStatus());
            self::assertNull($application->enrichmentResult());
            self::assertNull($application->enrichedAt());
        }
    }

    public function testItRejectsAnEnrichmentTimestampBeforeApplication(): void
    {
        $application = $this->application();
        $application->startEnrichment();

        $this->expectException(\InvalidArgumentException::class);
        $application->completeEnrichment(new EnrichmentResult('Summary', 50), new \DateTimeImmutable('2026-01-02T09:59:59Z'));
    }

    #[DataProvider('invalidApplicationText')]
    public function testItRejectsInvalidCvAndNotes(?string $notes, string $cvText): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->application(notes: $notes, cvText: $cvText);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function invalidApplicationText(): iterable
    {
        yield 'empty CV' => [null, '   '];
        yield 'overlong multibyte CV' => [null, str_repeat('á', 30_001)];
        yield 'overlong multibyte notes' => [str_repeat('á', 2_001), self::CV_TEXT];
    }

    private function application(
        ?\DateTimeImmutable $appliedAt = null,
        ?string $notes = null,
        string $cvText = self::CV_TEXT,
    ): Application {
        return new Application(
            ApplicationId::fromString('018f22e2-8f66-7f59-9a3d-1c2ecb22a261'),
            new Candidate('Ada Lovelace', new EmailAddress('ada@example.test'), '+44 20 1234 5678'),
            new JobId('backend-developer'),
            $notes,
            $cvText,
            $appliedAt ?? new \DateTimeImmutable('2026-01-02T10:00:00Z'),
        );
    }
}
