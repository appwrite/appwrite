<?php

use Utopia\Span\Exporter\Pretty;
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
    if (\str_starts_with($span->getAction(), 'listener.')) {
        return $span->getError() !== null;
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
$exporter = $loggingFormat === 'json'
    ? new Stdout(sampler: $sampler)
    : new Pretty(sampler: $sampler);

Span::setExporters($exporter);
