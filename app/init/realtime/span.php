<?php

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Export Realtime error spans to a dedicated Sentry project.
 *
 * Configured via `_APP_LOGGING_CONFIG_REALTIME`, falling back to `_APP_LOGGING_CONFIG` — the same
 * precedence the `realtimeLogger` registry uses. This exporter is registered only for the Realtime
 * server, not in `app/init/span.php` (shared by the HTTP / worker / CLI servers, which must keep
 * reporting to the default Sentry project). Only spans carrying an error are sampled: `logError()`
 * in `app/realtime.php` records the error onto the active — or a short-lived — span, which replaces
 * the legacy `utopia/logger` Sentry path for Realtime (see the `realtimeLogger` registry).
 */
$loggingConfig = System::getEnv('_APP_LOGGING_CONFIG_REALTIME', '') ?: System::getEnv('_APP_LOGGING_CONFIG', '');
if (empty($loggingConfig)) {
    return;
}

try {
    $dsn = new DSN($loggingConfig);

    if ($dsn->getScheme() === 'sentry') {
        // utopia/logger DSN (`sentry://PROJECT_ID:KEY@HOST/`) -> standard Sentry DSN (`https://KEY@HOST/PROJECT_ID`).
        $sentryDsn = 'https://' . ($dsn->getPassword() ?? '') . '@' . $dsn->getHost() . '/' . ($dsn->getUser() ?? '');
        $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';

        Span::addExporter(
            new Exporter\Sentry(
                dsn: $sentryDsn,
                environment: $isProduction ? 'production' : 'staging',
                release: System::getEnv('_APP_VERSION', 'UNKNOWN'),
                serverName: System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()),
            ),
            sampler: static fn (Span $span): bool => $span->getError() !== null,
        );
    }
} catch (Throwable $error) {
    Console::warning('Failed to register Realtime Sentry span exporter: ' . $error->getMessage());
}
