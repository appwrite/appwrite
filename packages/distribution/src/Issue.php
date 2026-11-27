<?php

declare(strict_types=1);

namespace Utopia\Distribution;

final readonly class Issue
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $field = null,
        public bool $manual = false,
        public ?string $reference = null,
    ) {
    }
}
