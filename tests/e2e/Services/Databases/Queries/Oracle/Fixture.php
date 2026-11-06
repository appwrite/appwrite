<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Fixture
{
    public function __construct(
        public string $databaseId,
        public string $customersId,
        public string $ordersId,
        public string $paymentsId,
    ) {
    }
}
