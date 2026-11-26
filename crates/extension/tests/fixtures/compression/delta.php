<?php

// A string as a delta on another, as pecl.json stores the streams the classes do not write:
// pecl.php writes the deltas, the corpus (../../compression.php) applies them. A delta is a list
// of "c" offset length (copy that much of the base) and "l" length bytes (these bytes), with
// offsets and lengths big-endian 32-bit.

/** `$target` as a delta on `$base`. */
function delta(string $base, string $target): string
{
    $block = 32;
    $index = [];
    for ($i = 0; $i + $block <= strlen($base); $i += $block) {
        $index[substr($base, $i, $block)] ??= $i;
    }
    $delta = '';
    $literal = '';
    $at = 0;
    $length = strlen($target);
    while ($at < $length) {
        $from = $at + $block <= $length ? ($index[substr($target, $at, $block)] ?? null) : null;
        if ($from === null) {
            $literal .= $target[$at++];
            continue;
        }
        // The copy grows back into the pending literal, then forward.
        $back = 0;
        while ($back < strlen($literal) && $from > $back && $base[$from - $back - 1] === $literal[strlen($literal) - $back - 1]) {
            $back++;
        }
        $literal = substr($literal, 0, strlen($literal) - $back);
        $from -= $back;
        $copied = $block + $back;
        $at += $block;
        while ($at < $length && $from + $copied < strlen($base) && $base[$from + $copied] === $target[$at]) {
            $copied++;
            $at++;
        }
        if ($literal !== '') {
            $delta .= 'l' . pack('N', strlen($literal)) . $literal;
            $literal = '';
        }
        $delta .= 'c' . pack('NN', $from, $copied);
    }
    if ($literal !== '') {
        $delta .= 'l' . pack('N', strlen($literal)) . $literal;
    }

    return $delta;
}

/** The string `$delta` describes on `$base`. */
function patched(string $base, string $delta): string
{
    $out = '';
    for ($at = 0; $at < strlen($delta);) {
        if ($delta[$at] === 'c') {
            [, $from, $length] = unpack('N2', $delta, $at + 1);
            $out .= substr($base, $from, $length);
            $at += 9;
        } else {
            $length = unpack('N', $delta, $at + 1)[1];
            $out .= substr($delta, $at + 5, $length);
            $at += 5 + $length;
        }
    }

    return $out;
}
