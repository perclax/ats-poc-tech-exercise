<?php

declare(strict_types=1);

namespace App\Applications\Application\Command;

final readonly class SubmitApplication
{
    public function __construct(
        public string $fullName,
        public string $email,
        public ?string $phone,
        public string $jobId,
        public ?string $notes,
        public string $cvText,
    ) {
    }
}
