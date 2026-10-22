<?php

declare(strict_types=1);

// Exports one span whose values carry control characters and invalid UTF-8, so a test can read
// what an exporter writes to its real stdout/stderr streams.

use Utopia\Span\Exporter\Pretty;
use Utopia\Span\Exporter\Stdout;
use Utopia\Span\Span;

require __DIR__ . '/../../../vendor/autoload.php';

$exporter = match ($argv[1] ?? '') {
    'pretty' => new Pretty(),
    'stdout' => new Stdout(),
    default => throw new InvalidArgumentException('Usage: export.php pretty|stdout'),
};

$span = new Span('test.encoding');
$span->set('control', "a\033[31m\nb");
$span->set('invalid', "ok\xB1\x31");
$span->set('plain', 'kept');

$exporter->export($span);
