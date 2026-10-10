<?php

// net.*: ip2long/long2ip, inet_pton/inet_ntop, idn_to_ascii/idn_to_utf8 (UTS #46).
// The IDN operations also report what the $idna_info argument receives.

return [
    'net.ip2long' => fn (array $a) => ip2long($a['ip']),
    'net.long2ip' => fn (array $a) => long2ip($a['ip']),
    'net.inet_pton' => fn (array $a) => inet_pton($a['ip']),
    'net.inet_ntop' => fn (array $a) => inet_ntop($a['packed']),
    'net.idn_to_ascii' => static function (array $a) {
        $result = idn_to_ascii($a['domain'], $a['flags'] ?? IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $info);

        return ['result' => $result, 'info' => $info];
    },
    'net.idn_to_utf8' => static function (array $a) {
        $result = idn_to_utf8($a['domain'], $a['flags'] ?? IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $info);

        return ['result' => $result, 'info' => $info];
    },
];
