<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Applications\Infrastructure\Persistence\Doctrine\ApplicationDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class MongoDbTestCase extends KernelTestCase
{
    protected DocumentManager $documentManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $documentManager = self::getContainer()->get(DocumentManager::class);
        self::assertInstanceOf(DocumentManager::class, $documentManager);
        $this->documentManager = $documentManager;
        $this->cleanApplications();
    }

    protected function tearDown(): void
    {
        $this->cleanApplications();
        parent::tearDown();
    }

    protected function cleanApplications(): void
    {
        $database = $this->documentManager->getConfiguration()->getDefaultDB();
        self::assertNotNull($database);
        self::assertSame('ats_test', $database, 'Cleanup requires the isolated test database.');
        $collection = $this->documentManager->getClassMetadata(ApplicationDocument::class)->getCollection();
        $this->documentManager->getClient()->selectCollection($database, $collection)->deleteMany([]);
        $this->documentManager->clear();
    }
}
