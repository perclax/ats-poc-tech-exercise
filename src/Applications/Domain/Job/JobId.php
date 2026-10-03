<?php

declare(strict_types=1);

namespace App\Applications\Domain\Job;

final readonly class JobId
{
    public function __construct(public string $value)
    {
        if (1 !== preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value)) {
            throw new \InvalidArgumentException('The job ID must be a lowercase slug.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
