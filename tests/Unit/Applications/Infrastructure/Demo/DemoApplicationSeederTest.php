<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Demo;

use App\Applications\Application\Port\ApplicationRepository;
use App\Applications\Domain\Application\Application;
use App\Applications\Domain\Application\ApplicationId;
use App\Applications\Infrastructure\Demo\DemoApplicationSeeder;
use App\Applications\Infrastructure\Enrichment\DeterministicCvEnricher;
use App\Applications\Infrastructure\Job\VersionControlledJobCatalog;
use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocumentMapper;
use PHPUnit\Framework\TestCase;

final class DemoApplicationSeederTest extends TestCase
{
    public function testRepositoryFailurePropagatesWithoutBeingReportedAsAConflict(): void
    {
        $repository = new class implements ApplicationRepository {
            public function find(ApplicationId $id): ?Application
            {
                throw new \RuntimeException('MongoDB unavailable');
            }

            public function save(Application $application): void
            {
                throw new \LogicException('No application should be saved.');
            }
        };
        $seeder = new DemoApplicationSeeder(
            $repository,
            new VersionControlledJobCatalog(),
            new DeterministicCvEnricher(),
            new ApplicationDocumentMapper(),
            $this->createStub(\Doctrine\ODM\MongoDB\DocumentManager::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MongoDB unavailable');
        $seeder->seed();
    }
}
