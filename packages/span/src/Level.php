<?php

declare(strict_types=1);

namespace Utopia\Span;

/**
 * Severity level of a span, ordered from least to most severe.
 *
 * Names follow Grafana Loki's `detected_level` vocabulary (note `warn`, not
 * `warning`). Exporters translate as needed — e.g. {@see Exporter\Sentry\Level}
 * maps `Warn` to Sentry's `warning`.
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warn = 'warn';
    case Error = 'error';
    case Fatal = 'fatal';
}
