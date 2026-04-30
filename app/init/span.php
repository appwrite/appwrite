<?php

use Utopia\Console;
use Utopia\DSN\DSN;
use Utopia\Span\Exporter;
use Utopia\Span\Exporter\SentryField;
use Utopia\Span\Span;
use Utopia\Span\Storage;
use Utopia\System\System;

Span::setStorage(new Storage\Coroutine());

$loggingConfig = System::getEnv('_APP_LOGGING_CONFIG', '');

$addSentryExporter = function (string $loggingConfig, ?callable $sampler = null): void {
    try {
        Span::addExporter(
            new Exporter\Sentry(
                dsn: $loggingConfig,
                environment: System::getEnv('_APP_ENV', 'development') === 'production' ? 'production' : 'staging',
                release: System::getEnv('_APP_VERSION', 'UNKNOWN'),
                serverName: System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()),
                classifier: fn (string $key): SentryField => match ($key) {
                    'appwrite.error.action',
                    'appwrite.error.publish',
                    'code',
                    'database',
                    'dnsDomain',
                    'embeddingModel',
                    'error.code',
                    'hostname',
                    'locale',
                    'method',
                    'projectId',
                    'service',
                    'type',
                    'url',
                    'userId',
                    'verboseType' => SentryField::Tag,
                    default => SentryField::Context,
                },
            ),
            sampler: $sampler ?? fn (Span $span): bool => $span->getError() !== null
                && $span->get('appwrite.error.publish') === true
                && $span->get('appwrite.error.experimental') !== true,
        );
    } catch (Throwable $th) {
        Console::warning('Failed to initialize logging provider: ' . $th->getMessage());
    }
};

if (!empty($loggingConfig)) {
    $addSentryExporter($loggingConfig);
}

$experimentalLoggingConfig = System::getEnv('_APP_EXPERIMENT_LOGGING_CONFIG', '');
if (!empty($experimentalLoggingConfig)) {
    $sampleRate = 0.01;
    try {
        $sample = (new DSN($experimentalLoggingConfig))->getParam('sample', $sampleRate);
        if (\is_numeric($sample)) {
            $sampleRate = \min(1, \max(0, (float) $sample));
        }
    } catch (Throwable) {
    }

    $addSentryExporter(
        loggingConfig: $experimentalLoggingConfig,
        sampler: fn (Span $span): bool => $span->getError() !== null
            && $span->get('appwrite.error.publish') === true
            && $span->get('appwrite.error.experimental') === true
            && \mt_rand() / \mt_getrandmax() <= $sampleRate,
    );
}

if (System::getEnv('_APP_ENV', 'development') === 'development') {
    Span::addExporter(new Exporter\Pretty(), function (Span $span): bool {
        if (\str_starts_with($span->getAction(), 'listener.')) {
            return $span->getError() !== null;
        }
        return true;
    });
}
