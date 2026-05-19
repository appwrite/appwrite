<?php

use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\Span\Storage;
use Utopia\System\System;

Span::setStorage(new Storage\Coroutine());

// Resolve trace filters once at boot to avoid repeated env lookups per span.
$traceProjectId = System::getEnv('_APP_TRACE_PROJECT_ID', '');
$traceFunctionId = System::getEnv('_APP_TRACE_FUNCTION_ID', '');
$traceEnabled = $traceProjectId !== '' || $traceFunctionId !== '';

Span::addExporter(new Exporter\Pretty(), function (Span $span) use ($traceEnabled, $traceProjectId, $traceFunctionId): bool {
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
});
