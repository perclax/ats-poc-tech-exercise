<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Infrastructure\Job;

use App\Applications\Domain\Job\JobId;
use App\Applications\Infrastructure\Job\VersionControlledJobCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionControlledJobCatalog::class)]
final class VersionControlledJobCatalogTest extends TestCase
{
    public function testItProvidesTheThreeConsistentVersionControlledJobs(): void
    {
        $catalog = new VersionControlledJobCatalog();
        $jobs = $catalog->all();

        self::assertSame(
            ['backend-developer', 'frontend-developer', 'fullstack-developer'],
            array_map(static fn ($job): string => $job->id->value, $jobs),
        );
        self::assertSame(
            ['Backend Developer', 'Frontend Developer', 'Fullstack Developer'],
            array_map(static fn ($job): string => $job->title, $jobs),
        );

        foreach ($jobs as $job) {
            self::assertNotSame('', trim($job->description));
            self::assertNotEmpty($job->skillGroups);
            foreach ($job->skillGroups as $skillGroup) {
                self::assertNotEmpty($skillGroup->aliases);
                self::assertNotContains('js', $skillGroup->aliases);
                self::assertNotContains('ts', $skillGroup->aliases);
                self::assertNotContains('rest', $skillGroup->aliases);
                self::assertCount(\count(array_unique($skillGroup->aliases)), $skillGroup->aliases);
            }
        }
    }

    public function testItDefinesTheExpectedSkillGroupsAndAliases(): void
    {
        $catalog = new VersionControlledJobCatalog();
        $backend = $catalog->find(new JobId('backend-developer'));
        $frontend = $catalog->find(new JobId('frontend-developer'));
        $fullstack = $catalog->find(new JobId('fullstack-developer'));

        self::assertNotNull($backend);
        self::assertNotNull($frontend);
        self::assertNotNull($fullstack);
        self::assertSame(
            ['PHP', 'Symfony', 'Databases', 'REST APIs'],
            array_map(static fn ($group): string => $group->name, $backend->skillGroups),
        );
        self::assertSame(
            ['JavaScript and TypeScript', 'React', 'HTML', 'CSS'],
            array_map(static fn ($group): string => $group->name, $frontend->skillGroups),
        );
        self::assertSame([...$backend->skillGroups, ...$frontend->skillGroups], $fullstack->skillGroups);
        self::assertSame(['rest api', 'rest apis', 'restful'], $backend->skillGroups[3]->aliases);
        self::assertSame(['javascript', 'typescript'], $frontend->skillGroups[0]->aliases);
        self::assertSame(['react', 'reactjs', 'react.js'], $frontend->skillGroups[1]->aliases);
        self::assertSame(['html', 'html5'], $frontend->skillGroups[2]->aliases);
        self::assertSame(['css', 'css3'], $frontend->skillGroups[3]->aliases);
    }

    public function testItReturnsNullForAnUnknownJob(): void
    {
        self::assertNull((new VersionControlledJobCatalog())->find(new JobId('unknown-job')));
    }
}
