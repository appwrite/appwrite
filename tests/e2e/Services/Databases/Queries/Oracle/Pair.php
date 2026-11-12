<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Queries\Oracle;

final readonly class Pair
{
    public function __construct(
        public ?Customer $customer,
        public ?Order $order,
    ) {
    }

    public function describe(): string
    {
        return ($this->customer->name ?? '-') . '|' . ($this->order->amount ?? '-');
    }
}
