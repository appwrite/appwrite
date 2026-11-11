<?php

// net.*: inet_pton/inet_ntop.

return [
    'net.inet_pton' => fn (array $a) => inet_pton($a['ip']),
    'net.inet_ntop' => fn (array $a) => inet_ntop($a['packed']),
];
