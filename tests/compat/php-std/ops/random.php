<?php

// random.*: the default engine (Mt19937) after mt_srand($seed):
// mt_rand($min, $min + $span) and array_rand() of one key from a list.

return [
    'random.mt_rand' => static function (array $a): array {
        mt_srand($a['seed']);
        $out = [];
        for ($i = 0; $i < $a['draws']; $i++) {
            $out[] = mt_rand($a['min'], $a['min'] + $a['span']);
        }

        return $out;
    },
    'random.array_rand' => static function (array $a): array {
        mt_srand($a['seed']);
        $list = $a['len'] > 0 ? range(0, $a['len'] - 1) : [];
        $out = [];
        for ($i = 0; $i < $a['draws']; $i++) {
            $out[] = array_rand($list);
        }

        return $out;
    },
];
