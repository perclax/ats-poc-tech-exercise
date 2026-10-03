<?php

declare(strict_types=1);

namespace App\Applications\Domain\Job;

final readonly class SkillGroup
{
    /** @var non-empty-list<string> */
    public array $aliases;

    /** @param list<string> $aliases */
    public function __construct(public string $name, array $aliases)
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A skill group name must not be empty.');
        }

        $normalizedAliases = [];
        foreach ($aliases as $alias) {
            $alias = mb_strtolower(trim($alias));
            if ('' === $alias) {
                throw new \InvalidArgumentException('A skill alias must not be empty.');
            }

            if (isset($normalizedAliases[$alias])) {
                throw new \InvalidArgumentException('Skill aliases must be unique within a group.');
            }

            $normalizedAliases[$alias] = true;
        }

        if ([] === $normalizedAliases) {
            throw new \InvalidArgumentException('A skill group must define at least one alias.');
        }

        /** @var non-empty-list<string> $normalizedAliasList */
        $normalizedAliasList = array_keys($normalizedAliases);
        $this->aliases = $normalizedAliasList;
    }
}
