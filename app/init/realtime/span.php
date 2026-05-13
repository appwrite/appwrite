<?php

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Export Realtime error spans to a dedicated Sentry project (`_APP_LOGGING_CONFIG_REALTIME`, falling
 * back to `_APP_LOGGING_CONFIG`) — only here, not in app/init/span.php, so the HTTP/worker/CLI
 * servers keep reporting to the default project. Only error-bearing spans are sampled.
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

// Print spans to stdout for local visibility on editions where app/init/span.php — which already
// installs a Pretty exporter — isn't loaded (it's gated on `_APP_EDITION === 'self-hosted'`).
if (System::getEnv('_APP_EDITION', 'self-hosted') !== 'self-hosted') {
    Span::addExporter(new Exporter\Pretty(), function (Span $span): bool {
        if (\str_starts_with($span->getAction(), 'listener.')) {
            return $span->getError() !== null;
        }
        return true;
    });
}
