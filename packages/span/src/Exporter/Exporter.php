<?php

declare(strict_types=1);

namespace Utopia\Span\Exporter;

use Utopia\Span\Span;

/**
 * Interface for span exporters.
 *
 * Implement this to send spans to your observability backend.
 */
interface Exporter
{
    /**
     * Export a finished span.
     *
     * Called after Span::finish() for spans where {@see self::sample()} returns true.
     * Use $span->getAttributes() for metadata and $span->getError() for exceptions.
     *
     * @param Span $span The finished span to export
     */
    public function export(Span $span): void;

    /**
     * Decide whether a span should be exported.
     *
     * Return false to drop the span. Implementations that always export should return true.
     *
     * @param Span $span The finished span to consider
     */
    public function sample(Span $span): bool;
}
