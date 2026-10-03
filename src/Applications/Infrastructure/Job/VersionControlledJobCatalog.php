<?php

declare(strict_types=1);

namespace App\Applications\Infrastructure\Job;

use App\Applications\Application\Port\JobCatalog;
use App\Applications\Domain\Job\Job;
use App\Applications\Domain\Job\JobId;
use App\Applications\Domain\Job\SkillGroup;

final class VersionControlledJobCatalog implements JobCatalog
{
    /** @var list<Job>|null */
    private ?array $jobs = null;

    public function all(): array
    {
        return $this->jobs ??= $this->createJobs();
    }

    public function find(JobId $id): ?Job
    {
        foreach ($this->all() as $job) {
            if ($job->id->value === $id->value) {
                return $job;
            }
        }

        return null;
    }

    /** @return list<Job> */
    private function createJobs(): array
    {
        $backendSkills = $this->backendSkills();
        $frontendSkills = $this->frontendSkills();

        return [
            new Job(
                new JobId('backend-developer'),
                'Backend Developer',
                'Build reliable backend services with PHP, Symfony, databases, and REST APIs.',
                $backendSkills,
            ),
            new Job(
                new JobId('frontend-developer'),
                'Frontend Developer',
                'Build accessible web interfaces with JavaScript, TypeScript, React, HTML, and CSS.',
                $frontendSkills,
            ),
            new Job(
                new JobId('fullstack-developer'),
                'Fullstack Developer',
                'Build end-to-end web features across backend services and frontend interfaces.',
                [...$backendSkills, ...$frontendSkills],
            ),
        ];
    }

    /** @return non-empty-list<SkillGroup> */
    private function backendSkills(): array
    {
        return [
            new SkillGroup('PHP', ['php']),
            new SkillGroup('Symfony', ['symfony']),
            new SkillGroup('Databases', ['database', 'databases', 'sql', 'mongodb']),
            new SkillGroup('REST APIs', ['rest api', 'restful']),
        ];
    }

    /** @return non-empty-list<SkillGroup> */
    private function frontendSkills(): array
    {
        return [
            new SkillGroup('JavaScript and TypeScript', ['javascript', 'typescript']),
            new SkillGroup('React', ['react', 'reactjs', 'react.js']),
            new SkillGroup('HTML', ['html', 'html5']),
            new SkillGroup('CSS', ['css', 'css3']),
        ];
    }
}
