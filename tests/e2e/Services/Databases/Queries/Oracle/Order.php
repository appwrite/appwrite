<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Order
{
    public function __construct(
        public string $id,
        public ?string $customerId,
        public int $amount,
        public string $label,
        public int $flags,
        public bool $hidden,
    ) {
    }
}
