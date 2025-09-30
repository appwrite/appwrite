<?php
require __DIR__ . '/vendor/autoload.php';
$reader = new MaxMind\Db\Reader(__DIR__ . '/app/assets/dbip/dbip-country-lite-2024-09.mmdb');
foreach ([
    '127.0.0.1',
    '::1',
    '192.168.1.1',
    '1.1.1.1',
    '8.8.8.8',
] as $ip) {
    try {
        $record = $reader->get($ip);
        echo $ip, ' => ';
        var_export($record);
        echo PHP_EOL;
    } catch (\Throwable $e) {
        echo $ip, ' error: ', get_class($e), ' ', $e->getMessage(), PHP_EOL;
    }
}
