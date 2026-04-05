<?php

use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\Span\Storage;

Span::setStorage(new Storage\Coroutine());
Span::addExporter(new Exporter\Pretty(), function (Span $span): bool {
    if (\str_starts_with($span->getAction(), 'listener.')) {
        return $span->getError() !== null;
    }
    return true;
});
