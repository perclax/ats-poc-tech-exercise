<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe\RabbitMq;

final readonly class ProbeReceiptStore
{
    private const int STALE_AFTER_SECONDS = 3600;

    public function __construct(private string $directory)
    {
    }

    public function record(string $correlationId): void
    {
        $path = $this->path($correlationId);
        $this->ensureDirectoryExists();
        $temporaryPath = \sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));

        if (false === file_put_contents($temporaryPath, $correlationId, \LOCK_EX)) {
            throw new \RuntimeException('Unable to write the infrastructure probe receipt.');
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish the infrastructure probe receipt.');
        }
    }

    public function has(string $correlationId): bool
    {
        return is_file($this->path($correlationId));
    }

    public function remove(string $correlationId): void
    {
        $path = $this->path($correlationId);

        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Unable to remove the infrastructure probe receipt.');
        }
    }

    public function removeStale(): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;
        $threshold = time() - self::STALE_AFTER_SECONDS;
        $files = glob($this->directory.'/probe-*.receipt');

        if (false === $files) {
            return 0;
        }

        foreach ($files as $path) {
            $basename = basename($path);
            if (1 !== preg_match('/\Aprobe-[a-f0-9]{64}\.receipt\z/', $basename)) {
                continue;
            }

            $modifiedAt = filemtime($path);
            if (false !== $modifiedAt && $modifiedAt < $threshold && unlink($path)) {
                ++$removed;
            }
        }

        return $removed;
    }

    private function path(string $correlationId): string
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $correlationId)) {
            throw new \InvalidArgumentException('Invalid infrastructure probe correlation ID.');
        }

        return \sprintf('%s/probe-%s.receipt', rtrim($this->directory, '/'), $correlationId);
    }

    private function ensureDirectoryExists(): void
    {
        if (!is_dir($this->directory) && !mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(\sprintf('Unable to create probe directory "%s".', $this->directory));
        }
    }
}
