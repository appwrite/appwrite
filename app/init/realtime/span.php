<?php

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Export Realtime error spans to a dedicated Sentry project, configured via
 * `_APP_LOGGING_CONFIG_REALTIME` (falls back to `_APP_LOGGING_CONFIG`). Registered only for the
 * Realtime server so the HTTP / worker / CLI servers keep reporting to the default Sentry project.
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
