<?php

namespace Utopia\Abuse;

final readonly class Result
{
    public function __construct(
        public bool $limited,
        public int $limit,
        public int $remaining,
        public int $reset,
    ) {
    }
}
