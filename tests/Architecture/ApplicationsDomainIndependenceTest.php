<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ApplicationsDomainIndependenceTest extends TestCase
{
    private const array FORBIDDEN_DEPENDENCY_PREFIXES = [
        'Symfony\\',
        'Doctrine\\',
        'MongoDB\\',
        'AMQP',
        'PhpAmqpLib\\',
        'App\\Applications\\Application\\',
        'App\\Applications\\Infrastructure\\',
        'App\\Applications\\Presentation\\',
    ];

    public function testDomainHasNoFrameworkOrOuterLayerDependencies(): void
    {
        $domainDirectory = \dirname(__DIR__, 2).'/src/Applications/Domain';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domainDirectory));
        $violations = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach (token_get_all($contents) as $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $dependency = ltrim($token[1], '\\');
                foreach (self::FORBIDDEN_DEPENDENCY_PREFIXES as $prefix) {
                    if (str_starts_with($dependency, $prefix)) {
                        $violations[] = \sprintf('%s:%d references %s', $file->getPathname(), $token[2], $dependency);
                    }
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
