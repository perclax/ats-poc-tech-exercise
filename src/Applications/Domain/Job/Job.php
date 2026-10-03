<?php

declare(strict_types=1);

namespace App\Applications\Domain\Job;

final readonly class Job
{
    /** @var non-empty-list<SkillGroup> */
    public array $skillGroups;

    /** @param list<SkillGroup> $skillGroups */
    public function __construct(
        public JobId $id,
        public string $title,
        public string $description,
        array $skillGroups,
    ) {
        if ('' === trim($title) || '' === trim($description)) {
            throw new \InvalidArgumentException('Job title and description must not be empty.');
        }

        if ([] === $skillGroups) {
            throw new \InvalidArgumentException('A job must define at least one skill group.');
        }

        $names = [];
        foreach ($skillGroups as $skillGroup) {
            $name = mb_strtolower(trim($skillGroup->name));
            if (isset($names[$name])) {
                throw new \InvalidArgumentException('Skill group names must be unique within a job.');
            }
            $names[$name] = true;
        }

        $this->skillGroups = $skillGroups;
    }
}
