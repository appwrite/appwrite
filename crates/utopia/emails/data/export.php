<?php

// Writes packages/emails/data/{free,disposable}-domains.php as free-domains.txt and
// disposable-domains.txt next to this script: one domain per line, in the PHP list's order.

foreach (['free', 'disposable'] as $list) {
    $domains = require __DIR__ . "/../../../../packages/emails/data/{$list}-domains.php";
    $lines = '';
    foreach ($domains as $domain) {
        if (!is_string($domain) || $domain === '' || strpbrk($domain, "\r\n") !== false) {
            fwrite(STDERR, "{$list}: cannot write " . var_export($domain, true) . PHP_EOL);
            exit(1);
        }
        $lines .= $domain . "\n";
    }
    file_put_contents(__DIR__ . "/{$list}-domains.txt", $lines);
    fwrite(STDERR, $list . ': ' . count($domains) . ' domains' . PHP_EOL);
}
