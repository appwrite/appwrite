<?php

/**
 * Downloads the Public Suffix List and writes data/data.php, the list Domain reads.
 *
 *   php data/import.php
 *
 * Environment (both optional; tests point them at fixtures, never at the internet):
 *   UTOPIA_DOMAINS_PSL_URL   where to download the list from (default: publicsuffix.org)
 *   UTOPIA_DOMAINS_DATA_DIR  the directory data.php is written to (default: this directory)
 *
 * Fails with a message on standard error and exit code 1 when the list cannot be
 * downloaded, has no `com` rule or cannot be written. The Rust port is the
 * `domains-import` binary of crates/utopia/domains.
 */
$url = getenv('UTOPIA_DOMAINS_PSL_URL') ?: 'https://publicsuffix.org/list/public_suffix_list.dat';
$dir = getenv('UTOPIA_DOMAINS_DATA_DIR') ?: __DIR__;

$data = @file_get_contents($url);
if ($data === false) {
    fwrite(STDERR, "Could not download public suffix list\n");
    exit(1);
}

$list = explode("\n", $data);

function arrayToCode(array $data, $level = 0): string
{
    $output = '[' . "\n";

    $level++;

    $tabs = str_repeat("\t", $level);

    foreach ($data as $key => $node) {
        $key = is_int($key) ? '' : var_export($key, true) . ' => ';
        $value = is_array($node) ? arrayToCode($node, $level) : var_export($node, true);
        $output .= $tabs . $key . $value . ",\n";
    }

    $level--;

    $tabs = str_repeat("\t", $level);

    $output .= $tabs . ']';

    return $output;
}

$type = null;
$comments = [];
$domains = [];

foreach ($list as $key => $line) {
    if (mb_strpos($line, '===BEGIN ICANN DOMAINS===')) {
        $type = 'ICANN';
        $comments = [];

        continue;
    }

    if (mb_strpos($line, '===END ICANN DOMAINS===')) {
        $type = null;

        continue;
    }

    if (mb_strpos($line, '===BEGIN PRIVATE DOMAINS===')) {
        $type = 'PRIVATE';
        $comments = [];

        continue;
    }

    if (mb_strpos($line, '===END PRIVATE DOMAINS===')) {
        $type = null;

        continue;
    }

    if (empty($line)) {
        continue;
    }

    if (mb_substr($line, 0, mb_strlen('// ')) === '// ') {
        $comments[] = mb_substr($line, mb_strlen('// '));

        continue;
    }

    $domains[$line] = [
        'suffix' => $line,
        'type' => $type,
        'comments' => $comments,
    ];

    $comments = [];
}

if (! isset($domains['com'])) {
    fwrite(STDERR, ".com is missing from public suffix list; it must be corrupted\n");
    exit(1);
}

if (@file_put_contents($dir . '/data.php', "<?php\n\nreturn " . arrayToCode($domains) . ';') === false) {
    fwrite(STDERR, "Could not write {$dir}/data.php\n");
    exit(1);
}
