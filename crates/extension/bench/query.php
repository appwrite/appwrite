<?php

// Times the Utopia\Query classifiers on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/query.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Classifier\PostgreSQL;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(MySQL::class))->isInternal();
$requested = in_array('query', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' query, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

$mysql = new MySQL();
$postgres = new PostgreSQL();
$mongo = new MongoDB();

// What a database proxy sees, one packet at a time: a simple query, a commented one, a CTE,
// a prepared statement and a MongoDB command.
$select = 'SELECT `_uid`, `name`, `email` FROM `app_users` WHERE `_tenant` = 42 AND `status` = \'active\' ORDER BY `_id` DESC LIMIT 25';
$commented = "/* request 7f3a */\n-- trace\n  UPDATE `app_users` SET `name` = 'x' WHERE `_uid` = 'abc'";
$cte = 'WITH recent AS (SELECT * FROM events WHERE time > now() - interval \'1 day\'), ranked AS (SELECT * FROM recent) INSERT INTO daily SELECT * FROM ranked';
$element = fn (string $key, string $value): string => "\x02" . $key . "\x00" . pack('V', strlen($value) + 1) . $value . "\x00";
$command = function (string ...$elements): string {
    $body = implode('', $elements) . "\x00";
    $body = pack('V', 0) . "\x00" . pack('V', 4 + strlen($body)) . $body;

    return pack('V', 16 + strlen($body)) . pack('V', 1) . pack('V', 0) . pack('V', 2013) . $body;
};
$find = $command($element('find', 'users'), $element('$db', 'app'));
$insert = $command($element('insert', 'users'), $element('$db', 'app'), $element('comment', str_repeat('x', 200)), "\x08startTransaction\x00\x01");

$cases = [
    'mysql COM_QUERY select' => fn () => $mysql->classify("\x00\x00\x00\x00\x03" . $select),
    'mysql COM_STMT_EXECUTE' => fn () => $mysql->classify("\x05\x00\x00\x00\x17\x01\x00\x00\x00"),
    'postgres Q select' => fn () => $postgres->classify('Q' . "\x00\x00\x00\x00" . $select . "\x00"),
    'postgres Q commented update' => fn () => $postgres->classify('Q' . "\x00\x00\x00\x00" . $commented . "\x00"),
    'classifySQL CTE insert' => fn () => $postgres->classifySQL($cte),
    'classifySQL begin' => fn () => $mysql->classifySQL('BEGIN'),
    'extractKeyword' => fn () => $mysql->extractKeyword($commented),
    'mongodb find' => fn () => $mongo->classify($find),
    'mongodb insert in a txn' => fn () => $mongo->classify($insert),
];
$n = 50000;
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
