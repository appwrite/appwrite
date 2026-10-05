<?php

declare(strict_types=1);

namespace Utopia\Span\Storage;

use Utopia\Span\Span;

/**
 * Interface for span context storage.
 *
 * Storage backends maintain the "current span" for the execution context,
 * enabling static methods like Span::add() to work without passing spans around.
 */
interface Storage
{
    /**
     * Get the current span for this execution context.
     *
     * @return Span|null The current span, or null if none
     */
    public function get(): ?Span;

    /**
     * Set the current span for this execution context.
     *
     * @param Span|null $span The span to set, or null to clear
     */
    public function set(?Span $span): void;
}
