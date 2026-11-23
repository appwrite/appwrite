<?php

declare(strict_types=1);

namespace Utopia\Distribution;

interface Progress
{
    /**
     * @param array<string, mixed> $checkpoint
     */
    public function save(array $checkpoint): void;
}
