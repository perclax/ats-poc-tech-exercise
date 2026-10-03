<?php

declare(strict_types=1);

namespace App\Tests\Unit\Applications\Domain\Job;

use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;
use App\Applications\Domain\Job\SkillGroup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Job::class)]
#[CoversClass(JobId::class)]
#[CoversClass(SkillGroup::class)]
final class JobTest extends TestCase
{
    public function testItDefinesAJobWithExplicitNormalizedAliases(): void
    {
        $job = new Job(new JobId('backend-developer'), 'Backend Developer', 'Build APIs.', [
            new SkillGroup('REST APIs', [' REST API ', 'RESTful']),
        ]);

        self::assertSame(['rest api', 'restful'], $job->skillGroups[0]->aliases);
    }

    public function testItRejectsAnInvalidJobId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobId('Backend Developer');
    }

    public function testItRejectsAJobWithoutSkills(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Job(new JobId('backend-developer'), 'Backend', 'Description', []);
    }

    public function testItRejectsDuplicateSkillGroups(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Job(new JobId('backend-developer'), 'Backend', 'Description', [
            new SkillGroup('PHP', ['php']),
            new SkillGroup(' php ', ['php8']),
        ]);
    }

    public function testItRejectsEmptyAndDuplicateAliases(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SkillGroup('PHP', ['PHP', ' php ']);
    }
}
