<?php

declare(strict_types=1);

namespace Utopia\Span\Exporter;

/**
 * Determines where a span attribute is placed in the Sentry event payload.
 *
 * - Tag: Indexed and searchable. Use for low-cardinality data (environment, version, tenant).
 * - Context: Not indexed. Recommended for structured debugging data (default).
 * - Extra: Not indexed. Deprecated by Sentry, use Context instead.
 */
enum SentryField
{
    case Tag;
    case Context;
    case Extra;
}
