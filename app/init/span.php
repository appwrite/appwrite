<?php

use Appwrite\Extend\Exception as AppwriteException;
use Utopia\DSN\DSN;
use Utopia\Span\Exporter\Pretty;
use Utopia\Span\Exporter\Sentry;
use Utopia\Span\Exporter\SentryField;
use Utopia\Span\Exporter\Stdout;
use Utopia\Span\Span;
use Utopia\Span\Storage;
use Utopia\System\System;

Span::setStorage(new Storage\Coroutine());

// Resolve trace filters once at boot to avoid repeated env lookups per span.
$traceProjectId = System::getEnv('_APP_TRACE_PROJECT_ID', '');
$traceFunctionId = System::getEnv('_APP_TRACE_FUNCTION_ID', '');
$traceEnabled = $traceProjectId !== '' || $traceFunctionId !== '';

$sampler = function (Span $span) use ($traceEnabled, $traceProjectId, $traceFunctionId): bool {
    $warning = $span->get('warning.message') !== null || (int) $span->get('embedding.errors') > 0;
    if ($warning && $span->getError() === null) {
        $span->set('level', 'warn');
    }

    if (\str_starts_with($span->getAction(), 'listener.')) {
        return $span->getError() !== null || $warning;
    }

    // Selective tracing: when _APP_TRACE_PROJECT_ID / _APP_TRACE_FUNCTION_ID are set,
    // only export spans tagged with matching project.id / function.id.
    if ($traceEnabled) {
        if ($traceProjectId !== '' && $span->get('project.id') !== $traceProjectId) {
            return false;
        }
        if ($traceFunctionId !== '' && $span->get('function.id') !== $traceFunctionId) {
            return false;
        }
    }

    return true;
};

// `_APP_LOGGING_FORMAT`: `pretty` (default) for multi-line terminal output;
// `json` for one NDJSON object per span (log aggregators).
$loggingFormat = \strtolower(System::getEnv('_APP_LOGGING_FORMAT', 'pretty'));
$exporters = [
    $loggingFormat === 'json'
        ? new Stdout(sampler: $sampler)
        : new Pretty(sampler: $sampler),
];

// `_APP_LOGGING_CONFIG`: a `sentry://PROJECT_ID:KEY@HOST/` DSN ships spans that
// carry an error to Sentry. Expected client errors stay out: an AppwriteException
// decides through its `publish` flag, anything else only when the code is 0 or 5xx.
$loggingConfig = System::getEnv('_APP_LOGGING_CONFIG', '');
if ($loggingConfig !== '') {
    try {
        $dsn = new DSN($loggingConfig);
        if ($dsn->getScheme() !== 'sentry') {
            throw new \InvalidArgumentException('Only the sentry:// scheme is supported');
        }

        $exporters[] = new Sentry(
            sampler: static function (Span $span): bool {
                $error = $span->getError();
                if ($error instanceof AppwriteException) {
                    return $error->isPublishable();
                }

                return $error === null || $error->getCode() === 0 || $error->getCode() >= 500;
            },
            dsn: 'https://' . $dsn->getPassword() . '@' . $dsn->getHost() . '/' . $dsn->getUser(),
            environment: System::getEnv('_APP_ENV', 'development') === 'production' ? 'production' : 'staging',
            release: System::getEnv('_APP_VERSION', 'UNKNOWN'),
            serverName: System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname() ?: null),
            classifier: static fn (string $key): SentryField => match ($key) {
                'project.id',
                'user.id',
                'http.method',
                'http.path',
                'http.hostname',
                'http.locale',
                'http.service',
                'error.action',
                'type',
                'domain',
                'function.id',
                'deployment.id',
                'database.id',
                'channel',
                'lock.target' => SentryField::Tag,
                default => SentryField::Context,
            },
        );
    } catch (\Throwable $th) {
        \error_log('Invalid _APP_LOGGING_CONFIG, error reporting is disabled: ' . $th->getMessage());
    }
}

Span::setExporters(...$exporters);
