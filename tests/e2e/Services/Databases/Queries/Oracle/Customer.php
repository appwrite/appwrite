<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Customer
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $hidden,
    ) {
    }
}
