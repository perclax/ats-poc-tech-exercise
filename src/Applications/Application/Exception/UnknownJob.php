<?php

declare(strict_types=1);

namespace App\Applications\Application\Exception;

final class UnknownJob extends \DomainException
{
    public static function withId(string $jobId): self
    {
        return new self(\sprintf('The job "%s" does not exist.', $jobId));
    }
}
