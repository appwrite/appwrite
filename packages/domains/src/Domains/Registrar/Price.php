<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

final readonly class Price
{
    public function __construct(
        public float $price,
        public bool $premium = false,
    ) {}
}
