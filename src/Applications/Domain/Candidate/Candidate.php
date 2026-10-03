<?php

declare(strict_types=1);

namespace App\Applications\Domain\Candidate;

final readonly class Candidate
{
    public string $fullName;
    public ?string $phone;

    public function __construct(string $fullName, public EmailAddress $email, ?string $phone)
    {
        $fullName = trim($fullName);
        if ('' === $fullName || mb_strlen($fullName) > 150) {
            throw new \InvalidArgumentException('The full name must not be empty and must be at most 150 characters.');
        }

        $phone = null === $phone ? null : trim($phone);
        if ('' === $phone) {
            $phone = null;
        }

        if (null !== $phone && mb_strlen($phone) > 30) {
            throw new \InvalidArgumentException('The phone number must be at most 30 characters.');
        }

        $this->fullName = $fullName;
        $this->phone = $phone;
    }
}
