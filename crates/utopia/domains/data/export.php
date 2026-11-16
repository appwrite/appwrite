<?php

// Prints packages/domains/data/data.php in the format of public-suffix-list.txt: each rule's
// comments as `#\t<comment>` lines, then `<ICANN|PRIVATE|->\t<rule>`, in the PHP list's order.

$list = require __DIR__ . '/../../../../packages/domains/data/data.php';
foreach ($list as $rule => $entry) {
    foreach ($entry['comments'] ?? [] as $comment) {
        echo "#\t", $comment, "\n";
    }
    echo match ($entry['type'] ?? null) {
        'ICANN' => 'ICANN',
        'PRIVATE' => 'PRIVATE',
        default => '-',
    }, "\t", $rule, "\n";
}
