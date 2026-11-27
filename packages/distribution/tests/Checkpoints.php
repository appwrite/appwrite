<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests;

use Utopia\Distribution\Progress;

final class Checkpoints implements Progress
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $saved = [];

    #[\Override]
    public function save(array $checkpoint): void
    {
        $this->saved[] = $checkpoint;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function saved(): array
    {
        return $this->saved;
    }
}
