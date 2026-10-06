<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Enrichment;

use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Enrichment\DeterministicCvEnricher;
use App\Applications\Infrastructure\Enrichment\SimulatedLatencyCvEnricher;
use App\Applications\Infrastructure\Job\VersionControlledJobCatalog;
use PHPUnit\Framework\TestCase;

final class SimulatedLatencyCvEnricherTest extends TestCase
{
    public function testItDelegatesWithoutChangingTheResult(): void
    {
        $job = (new VersionControlledJobCatalog())->find(new JobId('backend-developer'));
        self::assertNotNull($job);
        $inner = new DeterministicCvEnricher();

        $result = (new SimulatedLatencyCvEnricher($inner, 0))->enrich('PHP and Symfony', $job);

        self::assertEquals($inner->enrich('PHP and Symfony', $job), $result);
    }

    public function testNegativeLatencyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SimulatedLatencyCvEnricher(new DeterministicCvEnricher(), -1);
    }
}
