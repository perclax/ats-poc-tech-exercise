<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private const string CACHE_DIRECTORY_ENVIRONMENT_VARIABLE = 'APP_CACHE_DIR';

    public function getCacheDir(): string
    {
        $configuredDirectory = $_SERVER[self::CACHE_DIRECTORY_ENVIRONMENT_VARIABLE]
            ?? $_ENV[self::CACHE_DIRECTORY_ENVIRONMENT_VARIABLE]
            ?? null;
        if (null === $configuredDirectory) {
            return parent::getCacheDir();
        }
        if (!\is_string($configuredDirectory) || '' === $configuredDirectory || str_contains($configuredDirectory, "\0")) {
            throw new \InvalidArgumentException('APP_CACHE_DIR must be a non-empty absolute path inside the project cache directory.');
        }

        $configuredDirectory = rtrim($configuredDirectory, '/');
        $cacheRoot = $this->getProjectDir().'/var/cache';
        if (!str_starts_with($configuredDirectory, $cacheRoot.'/')) {
            throw new \InvalidArgumentException('APP_CACHE_DIR must be a non-empty absolute path inside the project cache directory.');
        }

        $relativeDirectory = substr($configuredDirectory, \strlen($cacheRoot) + 1);
        foreach (explode('/', $relativeDirectory) as $segment) {
            if (1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $segment)) {
                throw new \InvalidArgumentException('APP_CACHE_DIR contains an invalid path segment.');
            }
        }

        return $configuredDirectory;
    }
}
