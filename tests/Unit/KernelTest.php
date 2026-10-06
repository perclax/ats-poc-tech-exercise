<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    private bool $serverValueExisted;
    private mixed $serverValue;
    private bool $environmentValueExisted;
    private mixed $environmentValue;

    protected function setUp(): void
    {
        $this->serverValueExisted = \array_key_exists('APP_CACHE_DIR', $_SERVER);
        $this->serverValue = $_SERVER['APP_CACHE_DIR'] ?? null;
        $this->environmentValueExisted = \array_key_exists('APP_CACHE_DIR', $_ENV);
        $this->environmentValue = $_ENV['APP_CACHE_DIR'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironmentSource($_SERVER, $this->serverValueExisted, $this->serverValue);
        $this->restoreEnvironmentSource($_ENV, $this->environmentValueExisted, $this->environmentValue);
    }

    public function testDefaultCacheDirectoriesRemainEnvironmentSpecificWithoutOverride(): void
    {
        unset($_SERVER['APP_CACHE_DIR'], $_ENV['APP_CACHE_DIR']);
        $devKernel = new Kernel('dev', true);
        $testKernel = new Kernel('test', true);

        self::assertSame($devKernel->getProjectDir().'/var/cache/dev', $devKernel->getCacheDir());
        self::assertSame($testKernel->getProjectDir().'/var/cache/test', $testKernel->getCacheDir());
    }

    public function testValidServerOverrideAlsoSelectsTheBuildDirectory(): void
    {
        $kernel = new Kernel('dev', true);
        $workerCache = $kernel->getProjectDir().'/var/cache/worker';
        $_SERVER['APP_CACHE_DIR'] = $workerCache;
        unset($_ENV['APP_CACHE_DIR']);

        self::assertSame($workerCache, $kernel->getCacheDir());
        self::assertSame($workerCache, $kernel->getBuildDir());
        self::assertSame($workerCache, $kernel->getShareDir());
    }

    public function testEnvironmentOverrideIsUsedWhenServerValueIsAbsent(): void
    {
        $kernel = new Kernel('dev', true);
        $workerCache = $kernel->getProjectDir().'/var/cache/worker/nested';
        unset($_SERVER['APP_CACHE_DIR']);
        $_ENV['APP_CACHE_DIR'] = $workerCache.'/';

        self::assertSame($workerCache, $kernel->getCacheDir());
    }

    #[DataProvider('invalidCacheDirectories')]
    public function testInvalidOverrideIsRejectedLexically(string $configuredDirectory): void
    {
        $kernel = new Kernel('dev', true);
        $_SERVER['APP_CACHE_DIR'] = str_replace('{project}', $kernel->getProjectDir(), $configuredDirectory);
        unset($_ENV['APP_CACHE_DIR']);

        $this->expectException(\InvalidArgumentException::class);
        $kernel->getCacheDir();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCacheDirectories(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['var/cache/worker'];
        yield 'outside project cache' => ['{project}/var/worker-cache'];
        yield 'parent segment' => ['{project}/var/cache/dev/../worker'];
        yield 'current segment' => ['{project}/var/cache/./worker'];
        yield 'empty segment' => ['{project}/var/cache/worker//nested'];
        yield 'invalid segment character' => ['{project}/var/cache/worker cache'];
        yield 'NUL' => ["{project}/var/cache/worker\0nested"];
    }

    /** @param array<string, mixed> $source */
    private function restoreEnvironmentSource(array &$source, bool $existed, mixed $value): void
    {
        if ($existed) {
            $source['APP_CACHE_DIR'] = $value;

            return;
        }

        unset($source['APP_CACHE_DIR']);
    }
}
