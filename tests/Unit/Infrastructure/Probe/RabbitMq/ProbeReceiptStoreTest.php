<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Probe\RabbitMq;

use App\Infrastructure\Probe\RabbitMq\ProbeReceiptStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProbeReceiptStore::class)]
final class ProbeReceiptStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ats-probe-tests-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $files = glob($this->directory.'/*');
        if (false !== $files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        rmdir($this->directory);
    }

    public function testItRecordsDetectsAndRemovesACorrelationSpecificReceipt(): void
    {
        $store = new ProbeReceiptStore($this->directory);
        $correlationId = str_repeat('b', 64);

        $store->record($correlationId);
        self::assertTrue($store->has($correlationId));

        $store->remove($correlationId);
        self::assertFalse($store->has($correlationId));
    }

    public function testItOnlyRemovesValidStaleProbeReceipts(): void
    {
        mkdir($this->directory, 0775, true);
        $validPath = $this->directory.'/probe-'.str_repeat('c', 64).'.receipt';
        $unrelatedPath = $this->directory.'/keep.txt';
        file_put_contents($validPath, 'receipt');
        file_put_contents($unrelatedPath, 'unrelated');
        touch($validPath, time() - 7200);
        touch($unrelatedPath, time() - 7200);

        $store = new ProbeReceiptStore($this->directory);

        self::assertSame(1, $store->removeStale());
        self::assertFileDoesNotExist($validPath);
        self::assertFileExists($unrelatedPath);
    }
}
