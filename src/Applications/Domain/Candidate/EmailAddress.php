<?php

declare(strict_types=1);

namespace App\Applications\Domain\Candidate;

final readonly class EmailAddress
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ('' === $value || mb_strlen($value) > 254 || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('The email address must be valid and at most 254 characters.');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
