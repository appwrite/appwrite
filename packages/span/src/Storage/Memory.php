<?php

declare(strict_types=1);

namespace Utopia\Span\Storage;

use Utopia\Span\Span;

/**
 * Simple memory storage for single-process PHP (FPM, CLI)
 */
class Memory implements Storage
{
    private ?Span $span = null;

    public function get(): ?Span
    {
        return $this->span;
    }

    public function set(?Span $span): void
    {
        $this->span = $span;
    }
}
