<?php

declare(strict_types=1);

namespace App\Applications\Domain\Application;

final readonly class ApplicationId
{
    private const string UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::UUID_PATTERN, $value)) {
            throw new \InvalidArgumentException('The application ID must be a canonical lowercase UUID.');
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
