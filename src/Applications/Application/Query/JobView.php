<?php

declare(strict_types=1);

namespace App\Applications\Application\Query;

final readonly class JobView
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
    ) {
    }
}
