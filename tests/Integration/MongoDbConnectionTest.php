<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MongoDbConnectionTest extends KernelTestCase
{
    public function testConfiguredMongoDbConnectionRespondsToPing(): void
    {
        self::bootKernel();
        $documentManager = self::getContainer()->get(DocumentManager::class);
        self::assertInstanceOf(DocumentManager::class, $documentManager);

        $databaseName = $documentManager->getConfiguration()->getDefaultDB();
        self::assertNotNull($databaseName);

        $result = $documentManager->getClient()
            ->selectDatabase($databaseName)
            ->command(['ping' => 1])
            ->toArray();

        self::assertSame(1.0, $result[0]['ok'] ?? null);
    }
}
