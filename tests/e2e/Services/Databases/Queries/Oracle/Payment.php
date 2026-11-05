<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Payment
{
    public function __construct(
        public string $id,
        public string $orderId,
        public int $amount,
    ) {
    }
}
