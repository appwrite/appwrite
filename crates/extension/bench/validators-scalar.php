<?php

// Times the scalar leaf validators on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/validators-scalar.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\Numeric;
use Utopia\Validator\Port;
use Utopia\Validator\Range;
use Utopia\Validator\Wildcard;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Range::class))->isInternal();
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-scalar', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators-scalar, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// What requests do: a route builds its validators, then checks a parameter (often a query
// string's numeric strings, with loose validators).
$range = new Range(0, 5000);
$integer = new Integer(true);
$boolean = new Boolean(true);
$float = new FloatValidator(true);
$numeric = new Numeric();
$port = new Port();
$hex = new HexColor();
$wildcard = new Wildcard();
$cases = [
    'new Range' => fn () => new Range(1, 100),
    'new Integer' => fn () => new Integer(true),
    'Range isValid' => fn () => $range->isValid('25'),
    'Integer isValid' => fn () => $integer->isValid('25'),
    'Boolean isValid' => fn () => $boolean->isValid('true'),
    'FloatValidator isValid' => fn () => $float->isValid('1.5'),
    'Numeric isValid' => fn () => $numeric->isValid('1e3'),
    'Port isValid' => fn () => $port->isValid('127.0.0.1:8080'),
    'HexColor isValid' => fn () => $hex->isValid('ff00aa'),
    'Wildcard isValid' => fn () => $wildcard->isValid('x'),
    'Integer getDescription' => fn () => $integer->getDescription(),
    'Range getDescription' => fn () => $range->getDescription(),
];
$n = 20000;
$out = [];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 1000; $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
