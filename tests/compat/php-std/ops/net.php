<?php

// net.*: ip2long/long2ip, inet_pton/inet_ntop, idn_to_ascii/idn_to_utf8 (UTS #46).

return [
    'net.ip2long' => fn (array $a) => ip2long($a['ip']),
    'net.long2ip' => fn (array $a) => long2ip($a['ip']),
    'net.inet_pton' => fn (array $a) => inet_pton($a['ip']),
    'net.inet_ntop' => fn (array $a) => inet_ntop($a['packed']),
    'net.idn_to_ascii' => fn (array $a) => idn_to_ascii($a['domain'], $a['flags'], INTL_IDNA_VARIANT_UTS46),
    'net.idn_to_utf8' => fn (array $a) => idn_to_utf8($a['domain'], $a['flags'], INTL_IDNA_VARIANT_UTS46),
];
