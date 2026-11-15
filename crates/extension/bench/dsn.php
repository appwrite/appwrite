<?php

// Times Utopia\DSN\DSN on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/dsn.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\DSN\DSN;

$dsn = 'mariadb://appwrite_user:p%40ss%2Fword@mariadb.internal:3306/appwrite?charset=utf8mb4&timeout=5&ssl=true';
$cases = [
    'new DSN()' => fn () => new DSN($dsn),
    'new + all getters' => function () use ($dsn) {
        $d = new DSN($dsn);
        $d->getScheme();
        $d->getUser();
        $d->getPassword();
        $d->getHost();
        $d->getPort();
        $d->getPath();
        $d->getQuery();
    },
    'new + getParam() x3' => function () use ($dsn) {
        $d = new DSN($dsn);
        $d->getParam('charset');
        $d->getParam('timeout');
        $d->getParam('missing', 'x');
    },
    'DSN::from()' => fn () => DSN::from('s3', 'minio.example.com', 'admin@example.com', 'p@ss/word', 9000, 'bucket', ['region' => 'us-east-1']),
    '(string) $dsn' => (function () use ($dsn) {
        $d = new DSN($dsn);
        return fn () => (string) $d;
    })(),
];
$n = 200000;
$out = [];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 2000; $i++) {
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
