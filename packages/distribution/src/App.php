<?php

declare(strict_types=1);

namespace Utopia\Distribution;

final readonly class App
{
    public function __construct(
        public string $id,
        public string $identifier,
        public ?string $name = null,
    ) {
    }
}
