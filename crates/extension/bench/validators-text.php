<?php

// Times the text leaf validators on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/validators-text.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\Contains;
use Utopia\Validator\Identifier;
use Utopia\Validator\Label;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Text::class))->isInternal();
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-text', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators-text, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// What requests do: a route builds its validators, then checks a parameter (IDs against an
// allow list, names, enum-like strings against a white list).
$text = new Text(256);
$id = new Text(36, 1, [...Text::NUMBERS, ...Text::ALPHABET_LOWER, ...Text::ALPHABET_UPPER, '_', '-', '.']);
$blank = new Text(128, 1, [], true);
$identifier = new Identifier(64);
$white = new WhiteList(['json', 'yaml', 'xml', 'csv', 'TOML']);
$strict = new WhiteList(['json', 'yaml', 'xml', 'csv'], true);
$contains = new Contains(['.appwrite.', 'localhost', 'example']);
$label = new Label();
$cases = [
    'new Text' => fn () => new Text(256),
    'new WhiteList' => fn () => new WhiteList(['json', 'yaml', 'xml', 'csv', 'TOML']),
    'Text isValid' => fn () => $text->isValid('A display name, with some words in it'),
    'Text isValid allow list' => fn () => $id->isValid('6a1b2c3d4e5f_project-id.v2'),
    'Text isValid non-blank' => fn () => $blank->isValid('Ünïcödé name 日本'),
    'Identifier isValid' => fn () => $identifier->isValid('APPWRITE_FUNCTION_ID'),
    'WhiteList isValid' => fn () => $white->isValid('YAML'),
    'WhiteList strict isValid' => fn () => $strict->isValid('csv'),
    'Contains isValid' => fn () => $contains->isValid('Project.APPWRITE.global'),
    'Label isValid' => fn () => $label->isValid('my-site-123'),
    'Text getDescription' => fn () => $id->getDescription(),
    'WhiteList getDescription' => fn () => $white->getDescription(),
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
