<?php

namespace Appwrite\Span;

use Closure;
use Utopia\Span\Exporter\Exporter;
use Utopia\Span\Exporter\Pretty;
use Utopia\Span\Exporter\Stdout;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Builds the process-wide span exporter from `_APP_LOGGING_FORMAT`.
 *
 * `pretty` (default) writes multi-line terminal output; `json` writes one
 * NDJSON object per span for log aggregators.
 */
class Factory
{
    public const FORMAT_PRETTY = 'pretty';
    public const FORMAT_JSON = 'json';

    /**
     * @param Closure(Span): bool|null $sampler
     */
    public static function createExporter(?Closure $sampler = null, ?string $format = null): Exporter
    {
        $format = \strtolower($format ?? System::getEnv('_APP_LOGGING_FORMAT', self::FORMAT_PRETTY));

        return match ($format) {
            self::FORMAT_JSON => new Stdout(sampler: $sampler),
            default => new Pretty(sampler: $sampler),
        };
    }
}
