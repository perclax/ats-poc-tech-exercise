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
use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Enrichment\DeterministicCvEnricher;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Creates a few fictional, already-processed applications so the list is not empty on first start.
 */
final readonly class DemoApplicationSeeder
{
    public function __construct(
        private ApplicationRepository $repository,
        private JobCatalog $jobs,
        private DeterministicCvEnricher $enricher,
        private DocumentManager $documentManager,
    ) {
    }

    /** Returns the number of created applications; nothing is created when applications already exist. */
    public function seedIfEmpty(): int
    {
        if (0 !== $this->documentManager->getDocumentCollection(ApplicationDocument::class)->countDocuments()) {
            return 0;
        }

        $definitions = [
            ['Demo Avery Stone', 'demo.avery@example.test', 'backend-developer', '<strong>Fictional demo note</strong>', 'PHP, Symfony, MongoDB, and REST API.', '10:02', true],
            ['Demo Avery Stone', 'demo.avery@example.test', 'frontend-developer', null, 'Fictional portfolio: illustration and editorial writing.', '10:01', true],
            ['Demo Quinn Lake', 'demo.quinn@example.test', 'frontend-developer', null, 'React, HTML, and CSS. Fictional demonstration CV.', '10:00', false],
        ];
        foreach ($definitions as $index => [$name, $email, $jobId, $notes, $cv, $time, $completed]) {
            $application = new Application(
                ApplicationId::fromString(\sprintf('018f47a2-7b3c-7def-8123-%012d', $index + 1)),
                new Candidate($name, new EmailAddress($email), null),
                new JobId($jobId),
                $notes,
                $cv,
                new \DateTimeImmutable('2026-10-01T'.$time.':00Z'),
            );
            $application->startEnrichment();
            if ($completed) {
                $application->completeEnrichment($this->enrich($application), new \DateTimeImmutable('2026-10-01T'.$time.':30Z'));
            } else {
                $application->failEnrichment();
            }
            $this->repository->save($application);
        }

        return \count($definitions);
    }

    private function enrich(Application $application): EnrichmentResult
    {
        $job = $this->jobs->find($application->jobId()) ?? throw new \LogicException('A predefined demo job is missing from the catalogue.');

        return $this->enricher->enrich($application->cvText(), $job);
    }
}
