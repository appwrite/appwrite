<?php

declare(strict_types=1);

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Null exporter that discards all spans
 * Useful for testing or disabling tracing
 */
class None implements Exporter
{
    public function sample(Span $span): bool
    {
        return false;
    }

    public function export(Span $span): void
    {
        // Intentionally empty - discards all spans
    }
}
