<?php

use Utopia\Span\Exporter;
use Utopia\Span\Span;
use Utopia\Span\Storage;

Span::setStorage(new Storage\Coroutine());
Span::addExporter(new Exporter\Pretty());
