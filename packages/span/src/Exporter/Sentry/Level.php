<?php

declare(strict_types=1);

namespace Utopia\Span\Exporter\Sentry;

use Utopia\Span\Level as SpanLevel;

/**
 * Levels accepted by Sentry's event API.
 *
 * @see https://develop.sentry.dev/sdk/data-model/event-payloads/#optional-attributes
 */
enum Level: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Fatal = 'fatal';

    /**
     * Map a span {@see SpanLevel} to its Sentry equivalent.
     */
    public static function fromSpan(SpanLevel $level): self
    {
        return match ($level) {
            SpanLevel::Debug => self::Debug,
            SpanLevel::Info => self::Info,
            SpanLevel::Warn => self::Warning,
            SpanLevel::Error => self::Error,
            SpanLevel::Fatal => self::Fatal,
        };
    }
}
