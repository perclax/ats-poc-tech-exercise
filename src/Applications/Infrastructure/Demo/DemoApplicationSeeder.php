<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Demo;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Application\Port\JobCatalog;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Domain\Candidate\Candidate;
use App\Applications\Domain\Candidate\EmailAddress;
use App\Applications\Domain\Enrichment\EnrichmentResult;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Enrichment\DeterministicCvEnricher;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocumentMapper;
use Doctrine\ODM\MongoDB\DocumentManager;

final readonly class DemoApplicationSeeder
{
    public function __construct(
        private ApplicationRepository $repository,
        private JobCatalog $jobs,
        private DeterministicCvEnricher $enricher,
        private ApplicationDocumentMapper $mapper,
        private DocumentManager $documentManager,
    ) {
    }

    public function seedIfEmpty(): DemoSeedResult
    {
        if (0 !== $this->documentManager->getDocumentCollection(ApplicationDocument::class)->countDocuments()) {
            return new DemoSeedResult(true, 0, 0);
        }

        $report = $this->seed();

        return new DemoSeedResult(false, $report['created'], $report['alreadyPresent']);
    }

    /** @return array{created: int, alreadyPresent: int} */
    public function seed(): array
    {
        $missing = [];
        $conflicts = [];
        $expected = $this->applications();
        foreach ($expected as $application) {
            $existing = $this->repository->find($application->id());
            if (null === $existing) {
                $missing[] = $application;
            } elseif (!$this->matches($existing, $application)) {
                $conflicts[] = $application->id()->value;
            }
        }

        if ([] !== $conflicts) {
            throw new \UnexpectedValueException('Conflicting reserved demo application IDs: '.implode(', ', $conflicts).'. No records created.');
        }

        foreach ($missing as $application) {
            $this->repository->save($application);
        }

        return ['created' => \count($missing), 'alreadyPresent' => \count($expected) - \count($missing)];
    }

    /** @return list<Application> */
    private function applications(): array
    {
        $definitions = [
            ['Demo Avery Stone', 'demo.avery@example.test', 'backend-developer', '<strong>Fictional demo note</strong>', 'PHP, Symfony, MongoDB, and REST API.', '10:03', EnrichmentStatus::COMPLETED],
            ['Demo Avery Stone', 'demo.avery@example.test', 'frontend-developer', null, 'Fictional portfolio: illustration and editorial writing.', '10:02', EnrichmentStatus::COMPLETED],
            ['Demo Morgan Reed', 'demo.morgan@example.test', 'fullstack-developer', null, 'PHP and React. Fictional demonstration CV.', '10:01', EnrichmentStatus::PENDING],
            ['Demo Quinn Lake', 'demo.quinn@example.test', 'frontend-developer', null, 'React, HTML, and CSS. Fictional demonstration CV.', '10:00', EnrichmentStatus::FAILED],
        ];
        $applications = [];
        foreach ($definitions as $index => [$name, $email, $jobId, $notes, $cv, $time, $status]) {
            $application = new Application(
                ApplicationId::fromString(\sprintf('018f47a2-7b3c-7def-8123-%012d', $index + 1)),
                new Candidate($name, new EmailAddress($email), null),
                new JobId($jobId),
                $notes,
                $cv,
                new \DateTimeImmutable('2026-10-01T'.$time.':00Z'),
            );
            if (EnrichmentStatus::PENDING !== $status) {
                $application->startEnrichment();
                if (EnrichmentStatus::COMPLETED === $status) {
                    $application->completeEnrichment($this->result($application), new \DateTimeImmutable('2026-10-01T'.$time.':30Z'));
                } else {
                    $application->failEnrichment();
                }
            }
            $applications[] = $application;
        }

        return $applications;
    }

    private function result(Application $application): EnrichmentResult
    {
        $job = $this->jobs->find($application->jobId());
        if (null === $job) {
            throw new \LogicException('A predefined demo job is missing from the catalogue.');
        }

        return $this->enricher->enrich($application->cvText(), $job);
    }

    private function matches(Application $existing, Application $expected): bool
    {
        $actual = $this->mapper->toDocument($existing);
        $reference = $this->mapper->toDocument($expected);
        if (EnrichmentStatus::PENDING === $expected->enrichmentStatus()) {
            // Recovery may advance the pending demo. Never reset an existing result.
            if (EnrichmentStatus::COMPLETED === $existing->enrichmentStatus()
                && $existing->enrichmentResult() != $this->result($existing)) {
                return false;
            }
            $actual->enrichmentStatus = $reference->enrichmentStatus;
            $actual->enrichmentSummary = null;
            $actual->enrichmentScore = null;
            $actual->enrichedAt = null;
        }

        return $actual == $reference;
    }
}
