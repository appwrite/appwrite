<?php

/**
 * Child process of the span adapter's `exporter.export`: exports one described
 * span with Stdout or Pretty, which write to this process's STDOUT and STDERR.
 * Reads the operation's arguments as JSON on stdin.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../php/Adapter.php';
require_once __DIR__ . '/../php/Benchmarks.php';
require_once __DIR__ . '/../php/Codec.php';
require_once __DIR__ . '/../php/Fault.php';
require_once __DIR__ . '/../php/Session.php';
require_once __DIR__ . '/Adapter.php';

use Tests\Compat\Codec;
use Tests\Compat\Span\Adapter;

Adapter::exportHere(Codec::decode(json_decode((string) stream_get_contents(STDIN), false, 512, JSON_THROW_ON_ERROR)));
