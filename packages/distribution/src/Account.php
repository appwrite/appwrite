<?php

declare(strict_types=1);

namespace Utopia\Distribution;

final readonly class Account
{
    public function __construct(
        public string $id,
        public ?string $team = null,
    ) {
    }
}
