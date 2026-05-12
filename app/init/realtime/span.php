<?php

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Export Realtime error spans to a dedicated Sentry project (`_APP_LOGGING_CONFIG_REALTIME`, falling
 * back to `_APP_LOGGING_CONFIG`) — only here, not in app/init/span.php, so the HTTP/worker/CLI
 * servers keep reporting to the default project. The `realtimeLogger` registry skips the Sentry
 * logger for the same condition so each Realtime error is reported once; keep the two in sync.
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
