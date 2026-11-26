<?php

// Writes pecl.json: what PECL zstd, lz4 and snappy wrote for the compression corpus
// (../../compression.php), and which of the classes' bytes their decoders read back, so the corpus
// runs without them (appwrite/base 2.6 drops them; brotli stays and the corpus still calls it).
//
// Needs those three extensions and the utopia extension, as appwrite/base 2.5 has them:
//
//     docker build --target development --tag appwrite-utopia --build-arg TESTING=true .
//     docker run --rm --volume "$PWD/packages:/usr/src/code/packages" \
//         --volume "$PWD/crates:/usr/src/code/crates" appwrite-utopia \
//         php crates/extension/tests/fixtures/compression/pecl.php
//
// pecl.json holds:
//
// - "wrote": each stream a PECL function wrote, keyed by the call and the corpus input it took. The
//   classes write PHP's bytes byte for byte (crates/extension/README.md), so most entries are only
//   the stream's SHA-1: the corpus rebuilds it with the class counterpart named in the corpus
//   (zstd_compress level 9 is new Zstd(9)) and fails if the hash differs. The rest (only what
//   zstd_compress_add() wrote fed 100,000 bytes at a time: no class writes those frames) are
//   [sha1, base, delta]: a delta (delta.php), deflated and in base64, on the class counterpart's
//   bytes ("class"), on the input ("input") or on nothing ("none"), whichever is smallest.
// - "read": what zstd_uncompress(), lz4_uncompress() or snappy_uncompress() said of the classes'
//   bytes (read back to the input or not, and the warnings), as the corpus case keying it prints
//   it, with the SHA-1 of the bytes: the corpus prints it only for those bytes.

use Utopia\Compression\Algorithms\LZ4;
use Utopia\Compression\Algorithms\Snappy;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;

require __DIR__ . '/../../../../../vendor/autoload.php';
require __DIR__ . '/inputs.php';
require __DIR__ . '/delta.php';

ini_set('memory_limit', '-1');

foreach (['zstd_compress', 'lz4_compress', 'snappy_compress'] as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "$function() is missing: run this where PECL zstd, lz4 and snappy are loaded" . PHP_EOL);
        exit(1);
    }
}

$wrote = [];
/** Stores `$bytes`, written for `$input`, under `$key`: only their hash when `$counterpart` writes the same. */
function wrote(string $key, string $bytes, string $input, ?Compression $counterpart): void
{
    global $wrote;
    if (isset($wrote[$key])) {
        return;
    }
    $class = $counterpart?->compress($input);
    if ($class === $bytes) {
        $wrote[$key] = sha1($bytes);

        return;
    }
    $best = null;
    foreach (['class' => $class, 'input' => $input, 'none' => ''] as $base => $from) {
        if ($from === null || ($base === 'none' && $best !== null && strlen($bytes) > 1 << 20)) {
            continue;
        }
        $delta = gzdeflate(delta($from, $bytes), 9);
        if ($best === null || strlen($delta) < strlen($best[2])) {
            $best = [sha1($bytes), $base, $delta];
        }
    }
    $bases = ['class' => $class, 'input' => $input, 'none' => ''];
    if (patched($bases[$best[1]], gzinflate($best[2])) !== $bytes) {
        throw new LogicException("the delta of $key does not rebuild it");
    }
    $best[2] = base64_encode($best[2]);
    $wrote[$key] = $best;
}

// Section 2: what PHP's functions wrote, at every level, for every input.
$writers = [];
foreach ([-131072, -5, -1, 0, ...range(1, 22)] as $level) {
    $writers["zstd_compress level $level"] = [fn (string $in) => zstd_compress($in, $level), new Zstd($level)];
}
$writers['zstd_compress_add, unknown size'] = [function (string $in) {
    $context = zstd_compress_init(3);
    $out = '';
    foreach (str_split($in, 100_000) as $chunk) {
        $out .= zstd_compress_add($context, $chunk, false);
    }

    return $out . zstd_compress_add($context, '', true);
}, new Zstd(3)];
foreach (range(0, 12) as $level) {
    $writers["lz4_compress level $level"] = [fn (string $in) => lz4_compress($in, $level), new LZ4($level)];
}
$writers['snappy_compress'] = [fn (string $in) => snappy_compress($in), new Snappy()];
foreach ($writers as $name => [$write, $counterpart]) {
    foreach ($inputs as $label => $input) {
        wrote("$name, $label", $write($input), $input, $counterpart);
    }
}

// Section 3: the streams built from what they wrote.
foreach (['first', 'second', 'x'] as $input) {
    wrote("zstd_compress level 3, $input", zstd_compress($input), $input, new Zstd());
}
foreach (['small' => 'stream', 'large' => $inputs['text 1 MB'] . $inputs['text 1 MB'] . $inputs['zeros 256 KB']] as $label => $input) {
    wrote("zstd_compress_add level 1, $label", zstd_compress_add(zstd_compress_init(1), $input, true), $input, new Zstd(1));
}

// Section 5: 65 MB.
$large = large();
wrote('zstd_compress level 3, 65 MB', zstd_compress($large), $large, new Zstd());
wrote('zstd_compress_add level 3, 65 MB', zstd_compress_add(zstd_compress_init(), $large, true), $large, new Zstd());
wrote('lz4_compress level 0, 65 MB', lz4_compress($large), $large, new LZ4());
wrote('snappy_compress, 65 MB', snappy_compress($large), $large, new Snappy());

// Section 11: decompressed in a process with memory_limit=64M.
$a = str_repeat('a', 16 << 20);
wrote('zstd_compress level 3, 16 MB of a', zstd_compress($a), $a, new Zstd());

// Sections 1 and 5: what PHP's decoders said of the classes' bytes, as the corpus prints it.
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = [$level, $message];

    return true;
});
$read = [];
$configurations = [];
foreach (range(0, 12) as $level) {
    $configurations["LZ4 $level"] = [new LZ4($level), lz4_uncompress(...), in_array($level, [0, 1, 9, 12], true)];
}
$configurations['Snappy'] = [new Snappy(), snappy_uncompress(...), true];
foreach ([-5, -1, 0, ...range(1, 22)] as $level) {
    $configurations["Zstd $level"] = [new Zstd($level), zstd_uncompress(...), in_array($level, [-5, 1, 3, 9, 19, 22], true)];
}
foreach ($configurations as $name => [$algorithm, $uncompress, $onLarge]) {
    foreach ($inputs as $label => $input) {
        if (!$onLarge && !isset($small[$label])) {
            continue;
        }
        $compressed = $algorithm->compress($input);
        $warnings = [];
        $answer = ['value' => @$uncompress($compressed) === $input];
        if ($warnings !== []) {
            $answer['warnings'] = $warnings;
        }
        $read["$name read by PHP $label"] = [sha1($compressed), $answer];
    }
}
foreach (['Zstd 3' => [new Zstd(), zstd_uncompress(...)], 'Zstd 9' => [new Zstd(9), zstd_uncompress(...)], 'LZ4' => [new LZ4(), lz4_uncompress(...)], 'Snappy' => [new Snappy(), snappy_uncompress(...)]] as $name => [$algorithm, $uncompress]) {
    $compressed = $algorithm->compress($large);
    $read["$name read by PHP 65 MB"] = [sha1($compressed), sha1($uncompress($compressed)) === sha1($large)];
    unset($compressed);
}
restore_error_handler();

$json = json_encode(['wrote' => $wrote, 'read' => $read], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents(__DIR__ . '/pecl.json', $json . "\n");
fwrite(STDERR, sprintf("pecl.json: %d streams written, %d read, %d bytes\n", count($wrote), count($read), strlen($json) + 1));
