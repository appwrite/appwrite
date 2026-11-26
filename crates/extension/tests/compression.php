<?php

// Parity corpus for utopia-php/compression: the abstract Compression and its seven algorithms.
// The PHP classes are gone, so it runs on the utopia extension's and its stdout is diffed against
// compression.expected.jsonl, what it printed on the PHP classes (appwrite/appwrite main 8739256)
// before they were removed: every line must match.
//
// Reading what PHP's own functions wrote needs PECL zstd, brotli, lz4 and snappy (appwrite/base 2.5).
//
// Stored files are a wire format, so most of it is bytes: what each algorithm writes (as a hash,
// so a byte that differs fails the diff), what it reads back, and what PHP's own functions
// (gzencode(), gzdeflate(), zstd_compress(), brotli_compress(), lz4_compress(),
// snappy_compress(), and their incremental forms) wrote and the class must read, at every
// level, truncated, corrupted and concatenated, up to 64 MB. Then levels, arguments,
// negotiation, reflection and PHP subclasses. The known differences are asserted at its end.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Compression\Algorithms\Brotli;
use Utopia\Compression\Algorithms\Deflate;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\LZ4;
use Utopia\Compression\Algorithms\Snappy;
use Utopia\Compression\Algorithms\XZ;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;

const ALGORITHMS = [Brotli::class, Deflate::class, GZIP::class, LZ4::class, Snappy::class, XZ::class, Zstd::class];
const CLASSES = [Compression::class, ...ALGORITHMS];

ini_set('memory_limit', '-1');

$internal = (new ReflectionClass(Compression::class))->isInternal();
if (!$internal) {
    fwrite(STDERR, 'Utopia\\Compression is not the utopia extension\'s: this corpus checks the extension against pinned PHP output' . PHP_EOL);
    exit(1);
}
foreach (CLASSES as $class) {
    if ((new ReflectionClass($class))->isInternal() !== $internal) {
        fwrite(STDERR, $class . ' is not from the same implementation as Compression' . PHP_EOL);
        exit(1);
    }
}

/** Warnings, notices and deprecations raised by the current case: their level and message. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = [$level, $message];

    return true;
});

/** A value as the corpus prints it: short strings in hex, long ones by length and hash. */
function show(mixed $value): mixed
{
    if (is_string($value)) {
        return strlen($value) <= 48 ? ['hex' => bin2hex($value)] : ['len' => strlen($value), 'sha1' => sha1($value)];
    }
    if (is_object($value)) {
        return ['object' => get_class($value), 'state' => print_r($value, true)];
    }
    if (is_array($value)) {
        return array_map(show(...), $value);
    }
    if (is_float($value)) {
        return ['float' => var_export($value, true)];
    }

    return $value;
}

/** A message without the corpus's own path and line, so the pinned output survives edits to it. */
function unplaced(string $message): string
{
    return (string) preg_replace('/ in \S+\.php on line \d+/', ' in (file) on line (line)', $message);
}

/** What `$run` returns or throws, with the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => show($run())];
    } catch (Throwable $e) {
        $result = ['throws' => get_class($e), 'message' => unplaced($e->getMessage())];
    }
    if ($warnings !== []) {
        $result['warnings'] = array_map(fn (array $warning): array => [$warning[0], unplaced($warning[1])], $warnings);
    }

    return $result;
}

function emit(string $case, mixed $result): void
{
    echo json_encode([$case, $result], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), "\n";
}

/** `$size` bytes of text: words of a fixed vocabulary in a seeded random order. */
function text(int $size, int $seed = 42): string
{
    $randomizer = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar($seed));
    $words = [];
    for ($i = 0; $i < 2000; $i++) {
        $words[] = bin2hex($randomizer->getBytes(1 + $i % 5));
    }
    $parts = [];
    $length = 0;
    while ($length < $size) {
        $word = $words[$randomizer->getInt(0, 1999)] . ($randomizer->getInt(0, 9) === 0 ? ",\n" : ' ');
        $parts[] = $word;
        $length += strlen($word);
    }

    return substr(implode('', $parts), 0, $size);
}

function random(int $size, int $seed = 7): string
{
    return $size === 0 ? '' : (new Random\Randomizer(new Random\Engine\Xoshiro256StarStar($seed)))->getBytes($size);
}

$resources = __DIR__ . '/../../../packages/compression/tests/resources';
$inputs = [
    'empty' => '',
    'one byte' => 'a',
    'nul' => "\0",
    'short' => 'This is a demo string',
    'all bytes' => implode('', array_map(chr(...), range(0, 255))),
    'utf-8' => "日本語 é",
    'text 4 KB' => text(4096),
    'text 64 KB + 1' => text(65537),
    'random 4 KB' => random(4096),
    'zeros 256 KB' => str_repeat("\0", 256 << 10),
    'text 1 MB' => text(1 << 20),
    'random 1 MB' => random(1 << 20),
    'lorem' => file_get_contents($resources . '/disk-a/lorem.txt'),
    'jpg' => file_get_contents($resources . '/disk-a/kitten-1.jpg'),
    'png' => file_get_contents($resources . '/disk-b/kitten-1.png'),
];
/** The inputs small enough to run every level on. */
$small = array_filter($inputs, fn (string $input): bool => strlen($input) <= 65537);

/** The level-taking algorithms, at `$level`. */
function brotli(int $level): Brotli
{
    $brotli = new Brotli();
    $brotli->setLevel($level);

    return $brotli;
}

/** `$algorithm` configurations, named, each with the levels it is run at on small and large inputs. */
$configurations = [];
foreach (range(0, 11) as $level) {
    $configurations["Brotli $level"] = [fn () => brotli($level), true, in_array($level, [0, 1, 4, 9, 11], true)];
}
$configurations['Deflate'] = [fn () => new Deflate(), true, true];
$configurations['GZIP'] = [fn () => new GZIP(), true, true];
foreach (range(0, 12) as $level) {
    $configurations["LZ4 $level"] = [fn () => new LZ4($level), true, in_array($level, [0, 1, 9, 12], true)];
}
$configurations['Snappy'] = [fn () => new Snappy(), true, true];
foreach ([-5, -1, 0, ...range(1, 22)] as $level) {
    $configurations["Zstd $level"] = [fn () => new Zstd($level), true, in_array($level, [-5, 1, 3, 9, 19, 22], true)];
}

/** The PHP function that reads what `$name` wrote, to check PHP reads the class's bytes. */
function reader(string $name): ?callable
{
    return match (true) {
        str_starts_with($name, 'Brotli') => function_exists('brotli_uncompress') ? brotli_uncompress(...) : null,
        $name === 'Deflate' => gzinflate(...),
        $name === 'GZIP' => gzdecode(...),
        str_starts_with($name, 'LZ4') => function_exists('lz4_uncompress') ? lz4_uncompress(...) : null,
        $name === 'Snappy' => function_exists('snappy_uncompress') ? snappy_uncompress(...) : null,
        str_starts_with($name, 'Zstd') => function_exists('zstd_uncompress') ? zstd_uncompress(...) : null,
        default => null,
    };
}

// 1. What each algorithm writes, at every level, and what reads it back -----------------------

foreach ($configurations as $name => [$make, $onSmall, $onLarge]) {
    foreach ($inputs as $label => $input) {
        $isSmall = isset($small[$label]);
        if (!($isSmall ? $onSmall : $onLarge)) {
            continue;
        }
        $algorithm = $make();
        $compressed = '';
        emit("$name compress $label", outcome(function () use ($algorithm, $input, &$compressed) {
            return $compressed = $algorithm->compress($input);
        }));
        emit("$name round trip $label", outcome(fn () => $algorithm->decompress($compressed) === $input));
        $read = reader($name);
        if ($read !== null) {
            emit("$name read by PHP $label", outcome(fn () => @$read($compressed) === $input));
        }
    }
}

// 2. What PHP's functions wrote, read by the classes ------------------------------------------

/** `$name` (the PHP function and its arguments) => [the class reading it, a function writing it]. */
$writers = [];
foreach ([-1, 0, 1, 6, 9] as $level) {
    $writers["gzencode level $level"] = [GZIP::class, fn (string $in) => gzencode($in, $level)];
    $writers["gzdeflate level $level"] = [Deflate::class, fn (string $in) => gzdeflate($in, $level)];
}
foreach (range(2, 8) as $level) {
    $writers["gzencode level $level"] = [GZIP::class, fn (string $in) => gzencode($in, $level)];
    $writers["gzdeflate level $level"] = [Deflate::class, fn (string $in) => gzdeflate($in, $level)];
}
$writers['zlib_encode gzip'] = [GZIP::class, fn (string $in) => zlib_encode($in, ZLIB_ENCODING_GZIP)];
$writers['zlib_encode raw'] = [Deflate::class, fn (string $in) => zlib_encode($in, ZLIB_ENCODING_RAW)];
$writers['deflate_add gzip, synced'] = [GZIP::class, function (string $in) {
    $context = deflate_init(ZLIB_ENCODING_GZIP);
    $out = '';
    foreach (str_split($in === '' ? ' ' : $in, 1000) as $chunk) {
        $out .= deflate_add($context, $in === '' ? '' : $chunk, ZLIB_SYNC_FLUSH);
    }

    return $out . deflate_add($context, '', ZLIB_FINISH);
}];
$writers['deflate_add raw, full flush'] = [Deflate::class, function (string $in) {
    $context = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9, 'strategy' => ZLIB_FILTERED]);
    $out = '';
    foreach (str_split($in, 777) as $chunk) {
        $out .= deflate_add($context, $chunk, ZLIB_FULL_FLUSH);
    }

    return $out . deflate_add($context, '', ZLIB_FINISH);
}];
if (function_exists('zstd_compress')) {
    foreach ([-131072, -5, -1, 0, ...range(1, 22)] as $level) {
        $writers["zstd_compress level $level"] = [Zstd::class, fn (string $in) => zstd_compress($in, $level)];
    }
    $writers['zstd_compress_add, unknown size'] = [Zstd::class, function (string $in) {
        $context = zstd_compress_init(3);
        $out = '';
        foreach (str_split($in, 100_000) as $chunk) {
            $out .= zstd_compress_add($context, $chunk, false);
        }

        return $out . zstd_compress_add($context, '', true);
    }];
}
if (function_exists('brotli_compress')) {
    foreach (range(0, 11) as $level) {
        foreach ([BROTLI_GENERIC, BROTLI_TEXT, BROTLI_FONT] as $mode) {
            $writers["brotli_compress level $level mode $mode"] = [Brotli::class, fn (string $in) => brotli_compress($in, $level, $mode)];
        }
    }
    $writers['brotli_compress_add, flushed'] = [Brotli::class, function (string $in) {
        $context = brotli_compress_init(5);
        $out = '';
        foreach (str_split($in, 50_000) as $chunk) {
            $out .= brotli_compress_add($context, $chunk, BROTLI_FLUSH);
        }

        return $out . brotli_compress_add($context, '', BROTLI_FINISH);
    }];
}
if (function_exists('lz4_compress')) {
    foreach (range(0, 12) as $level) {
        $writers["lz4_compress level $level"] = [LZ4::class, fn (string $in) => lz4_compress($in, $level)];
    }
}
if (function_exists('snappy_compress')) {
    $writers['snappy_compress'] = [Snappy::class, fn (string $in) => snappy_compress($in)];
}
foreach ($writers as $name => [$class, $write]) {
    // The slowest levels run on the small inputs only.
    $slow = preg_match('/^(brotli_compress level (10|11)|zstd_compress level (19|2[0-2])) /', $name) === 1;
    foreach ($inputs as $label => $input) {
        if ($slow && !isset($small[$label])) {
            continue;
        }
        $bytes = $write($input);
        $algorithm = new $class();
        emit("$class reads $name, $label", outcome(fn () => [strlen($bytes), $algorithm->decompress($bytes) === $input]));
    }
}

// 3. Streams PHP never wrote whole: concatenated, padded, with headers, legacy ----------------

$text = $inputs['text 4 KB'];
$gzip = gzencode($text);
$deflated = gzdeflate($text);
/** A gzip member with the optional header fields PHP never writes. */
function member(string $data, int $flags, string $extra = '', string $name = '', string $comment = ''): string
{
    $header = "\x1f\x8b\x08" . chr($flags) . pack('V', 1_700_000_000) . "\x02\x0b";
    if ($flags & 4) {
        $header .= pack('v', strlen($extra)) . $extra;
    }
    if ($flags & 8) {
        $header .= $name . "\0";
    }
    if ($flags & 16) {
        $header .= $comment . "\0";
    }
    if ($flags & 2) {
        $header .= pack('v', crc32($header) & 0xffff);
    }

    return $header . gzdeflate($data) . pack('V', crc32($data)) . pack('V', strlen($data));
}
$streams = [
    'GZIP two members' => [new GZIP(), gzencode('first') . gzencode('second')],
    'GZIP member then garbage' => [new GZIP(), $gzip . 'garbage'],
    'GZIP member then NUL' => [new GZIP(), $gzip . "\0"],
    'GZIP with name' => [new GZIP(), member($text, 8, name: 'file.txt')],
    'GZIP with comment' => [new GZIP(), member($text, 16, comment: 'a comment')],
    'GZIP with extra' => [new GZIP(), member($text, 4, extra: 'AP' . pack('v', 2) . 'xy')],
    'GZIP with header CRC' => [new GZIP(), member($text, 2)],
    'GZIP with every field' => [new GZIP(), member($text, 2 | 4 | 8 | 16, 'AB' . pack('v', 0), 'n', 'c')],
    'GZIP reserved flag' => [new GZIP(), member($text, 32)],
    'GZIP wrong CRC' => [new GZIP(), substr($gzip, 0, -8) . pack('V', 0) . substr($gzip, -4)],
    'GZIP wrong size' => [new GZIP(), substr($gzip, 0, -4) . pack('V', 1)],
    'GZIP wrong size, large' => [new GZIP(), substr($gzip, 0, -4) . pack('V', 0xffffffff)],
    'GZIP no trailer' => [new GZIP(), substr($gzip, 0, -8)],
    'GZIP zlib format' => [new GZIP(), gzcompress($text)],
    'GZIP raw deflate' => [new GZIP(), $deflated],
    'GZIP header only' => [new GZIP(), substr($gzip, 0, 10)],
    'GZIP empty member' => [new GZIP(), gzencode('')],
    'GZIP not gzip method' => [new GZIP(), "\x1f\x8b\x07" . substr($gzip, 3)],
    'Deflate then garbage' => [new Deflate(), $deflated . 'garbage'],
    'Deflate two streams' => [new Deflate(), gzdeflate('first') . gzdeflate('second')],
    'Deflate empty stream' => [new Deflate(), "\x03\x00"],
    'Deflate stored block' => [new Deflate(), gzdeflate('stored', 0)],
    'Deflate gzip' => [new Deflate(), $gzip],
    'Deflate zlib' => [new Deflate(), gzcompress($text)],
    'Deflate unfinished block' => [new Deflate(), "\x00\x05\x00\xfa\xffab"],
    'Deflate invalid block type' => [new Deflate(), "\x07\x00"],
    'Deflate NUL' => [new Deflate(), "\0"],
    'Deflate highly compressed' => [new Deflate(), gzdeflate(str_repeat('a', 10 << 20), 9)],
    'GZIP highly compressed' => [new GZIP(), gzencode(str_repeat("\0", 20 << 20), 9)],
];
if (function_exists('zstd_compress')) {
    $frame = zstd_compress($text);
    $skippable = pack('V', 0x184D2A50) . pack('V', 4) . 'skip';
    $streams += [
        'Zstd two frames' => [new Zstd(), zstd_compress('first') . zstd_compress('second')],
        'Zstd frame then garbage' => [new Zstd(), $frame . 'garbage'],
        'Zstd skippable frame' => [new Zstd(), $skippable],
        'Zstd skippable then frame' => [new Zstd(), $skippable . $frame],
        'Zstd frame then skippable' => [new Zstd(), $frame . $skippable],
        'Zstd empty frame' => [new Zstd(), zstd_compress('')],
        'Zstd magic only' => [new Zstd(), "\x28\xb5\x2f\xfd"],
        'Zstd header only' => [new Zstd(), substr($frame, 0, 6)],
        'Zstd wrong magic' => [new Zstd(), "\x29" . substr($frame, 1)],
        'Zstd frame claiming more' => [new Zstd(), substr_replace($frame, pack('v', 5000), 5, 2)],
        'Zstd v0.1 magic' => [new Zstd(), pack('V', 0x1EB52FFD) . substr($frame, 4)],
        'Zstd v0.2 magic' => [new Zstd(), pack('V', 0xFD2FB522) . substr($frame, 4)],
        'Zstd v0.3 magic' => [new Zstd(), pack('V', 0xFD2FB523) . substr($frame, 4)],
        'Zstd v0.4 magic' => [new Zstd(), pack('V', 0xFD2FB524) . substr($frame, 4)],
    ];
    // Frames without a content size, from the incremental API: small, and larger than one window.
    foreach (['small' => 'stream', 'large' => $inputs['text 1 MB'] . $inputs['text 1 MB'] . $inputs['zeros 256 KB']] as $label => $input) {
        $context = zstd_compress_init(1);
        $streams["Zstd streamed frame, $label"] = [new Zstd(), zstd_compress_add($context, $input, true)];
    }
}
// Legacy frames (v0.4 to v0.7), written by those versions of the zstd tool.
foreach (glob(__DIR__ . '/fixtures/compression/zstd-v0.*.zst') ?: [] as $fixture) {
    $streams['Zstd legacy ' . basename($fixture)] = [new Zstd(), file_get_contents($fixture)];
}
if (function_exists('brotli_compress')) {
    $stream = brotli_compress($text);
    $streams += [
        'Brotli stream then garbage' => [new Brotli(), $stream . 'garbage'],
        'Brotli two streams' => [new Brotli(), brotli_compress('first') . brotli_compress('second')],
        'Brotli empty stream' => [new Brotli(), "\x3b"],
        'Brotli metadata only' => [new Brotli(), "\x0d\x00\x03"],
        'Brotli large output' => [new Brotli(), brotli_compress(str_repeat('a', 3 << 20), 9)],
    ];
}
if (function_exists('lz4_compress')) {
    $block = lz4_compress($text);
    $streams += [
        'LZ4 larger prefix' => [new LZ4(), pack('V', strlen($text) + 100) . substr($block, 4)],
        'LZ4 smaller prefix' => [new LZ4(), pack('V', strlen($text) - 1) . substr($block, 4)],
        'LZ4 huge prefix' => [new LZ4(), pack('V', 0x7fffffff) . substr($block, 4)],
        'LZ4 negative prefix' => [new LZ4(), pack('V', 0x80000000) . substr($block, 4)],
        'LZ4 zero prefix' => [new LZ4(), pack('V', 0) . substr($block, 4)],
        'LZ4 prefix only' => [new LZ4(), pack('V', 10)],
        'LZ4 short' => [new LZ4(), "\x01\x00\x00"],
        'LZ4 block then garbage' => [new LZ4(), $block . 'garbage'],
        'LZ4 empty input block' => [new LZ4(), lz4_compress('')],
    ];
}
if (function_exists('snappy_compress')) {
    $snapped = snappy_compress($text);
    $streams += [
        'Snappy longer length' => [new Snappy(), "\xff\x7f" . substr($snapped, 2)],
        'Snappy shorter length' => [new Snappy(), "\xfe\x1f" . substr($snapped, 2)],
        'Snappy unterminated length' => [new Snappy(), "\xff\xff\xff\xff\xff"],
        'Snappy length too long' => [new Snappy(), "\xff\xff\xff\xff\x10"],
        'Snappy empty' => [new Snappy(), snappy_compress('')],
        'Snappy then garbage' => [new Snappy(), $snapped . 'garbage'],
        'Snappy zero offset' => [new Snappy(), "\x08\x00a\x01\x00"],
        'Snappy offset past start' => [new Snappy(), "\x08\x00a\x05\x05"],
        'Snappy 4-byte offset copy' => [new Snappy(), "\x0a\x04ab\x0f\x02\x00\x00\x00\x0b\x02\x00\x00\x00"],
        'Snappy long literal' => [new Snappy(), "\x05\xf0\x04\x00abcde"],
        'Snappy literal past input' => [new Snappy(), "\x05\x10ab"],
        'Snappy huge length' => [new Snappy(), "\xff\xff\xff\xff\x0f\x00a"],
    ];
}
foreach ($streams as $name => [$algorithm, $bytes]) {
    emit("stream $name", outcome(fn () => $algorithm->decompress($bytes)));
}

// 4. Truncated and corrupted input, and input of the wrong algorithm --------------------------

/** The content size a zstd frame's header claims, or null when it has no header or no size. */
function claimed(string $frame): ?int
{
    if (strlen($frame) < 5 || !str_starts_with($frame, "\x28\xb5\x2f\xfd")) {
        return null;
    }
    $descriptor = ord($frame[4]);
    $single = ($descriptor >> 5) & 1;
    $width = [$single ? 1 : 0, 2, 4, 8][$descriptor >> 6];
    $at = 5 + ($single ? 0 : 1) + [0, 1, 2, 4][$descriptor & 3];
    if ($width === 0 || strlen($frame) < $at + $width) {
        return null;
    }
    $field = substr($frame, $at, $width);

    return match ($width) {
        1 => ord($field),
        2 => unpack('v', $field)[1] + 256,
        4 => unpack('V', $field)[1],
        default => unpack('P', $field)[1],
    };
}

/**
 * Decompresses `$bytes`, unless it is a zstd frame claiming more than 256 MB: both
 * implementations allocate the claimed size first, which ends the process (section 11 checks
 * that in a process of its own).
 */
function decompressed(Compression $algorithm, string $bytes): array
{
    $claim = $algorithm instanceof Zstd ? claimed($bytes) : null;
    if ($claim !== null && ($claim < 0 || $claim > 256 << 20)) {
        return ['claims' => $claim];
    }

    return outcome(fn () => $algorithm->decompress($bytes));
}

$samples = ['short' => $inputs['short'], 'text 4 KB' => $text];
foreach ([new Brotli(), new Deflate(), new GZIP(), new LZ4(), new Snappy(), new Zstd()] as $algorithm) {
    $name = $algorithm->getName();
    foreach ($samples as $label => $input) {
        $compressed = $algorithm->compress($input);
        $length = strlen($compressed);
        foreach (array_unique([0, 1, 2, 3, 4, 5, 9, 10, intdiv($length, 2), $length - 2, $length - 1]) as $cut) {
            if ($cut >= 0 && $cut < $length) {
                emit("$name truncated to $cut, $label", decompressed($algorithm, substr($compressed, 0, $cut)));
            }
        }
        foreach (array_unique([0, 1, 2, 3, 4, 8, 9, 12, intdiv($length, 3), intdiv($length, 2), $length - 5, $length - 1]) as $at) {
            if ($at >= 0 && $at < $length) {
                foreach ([0x01, 0x80, 0xff] as $mask) {
                    $corrupt = $compressed;
                    $corrupt[$at] = chr(ord($corrupt[$at]) ^ $mask);
                    emit("$name byte $at ^ $mask, $label", decompressed($algorithm, $corrupt));
                }
            }
        }
    }
    foreach (['empty' => '', 'garbage' => 'garbage', 'nul' => "\0", 'zeros' => str_repeat("\0", 64), 'text' => $text] as $label => $junk) {
        emit("$name decompress $label", outcome(fn () => $algorithm->decompress($junk)));
    }
    foreach ([new Brotli(), new Deflate(), new GZIP(), new LZ4(), new Snappy(), new Zstd()] as $other) {
        if ($other->getName() !== $name) {
            $foreign = $other->compress($text);
            emit("$name decompress {$other->getName()}", outcome(fn () => $algorithm->decompress($foreign)));
        }
    }
}

// 5. 64 MB and more, both ways ----------------------------------------------------------------

$large = text(64 << 20, 11) . random(1 << 20, 12);
foreach (['GZIP' => new GZIP(), 'Deflate' => new Deflate(), 'Zstd 3' => new Zstd(), 'Zstd 9' => new Zstd(9), 'Brotli 4' => brotli(4), 'LZ4' => new LZ4(), 'Snappy' => new Snappy()] as $name => $algorithm) {
    $compressed = $algorithm->compress($large);
    emit("$name compress 65 MB", show($compressed));
    emit("$name round trip 65 MB", sha1($algorithm->decompress($compressed)) === sha1($large));
    $read = reader($name);
    if ($read !== null) {
        emit("$name read by PHP 65 MB", sha1($read($compressed)) === sha1($large));
    }
    unset($compressed);
}
$largeWriters = ['gzencode' => [new GZIP(), fn () => gzencode($large)], 'gzdeflate' => [new Deflate(), fn () => gzdeflate($large)]];
if (function_exists('zstd_compress')) {
    $largeWriters['zstd_compress'] = [new Zstd(), fn () => zstd_compress($large)];
    $largeWriters['zstd_compress_add'] = [new Zstd(), fn () => zstd_compress_add(zstd_compress_init(), $large, true)];
}
if (function_exists('brotli_compress')) {
    $largeWriters['brotli_compress level 2'] = [new Brotli(), fn () => brotli_compress($large, 2)];
}
if (function_exists('lz4_compress')) {
    $largeWriters['lz4_compress'] = [new LZ4(), fn () => lz4_compress($large)];
}
if (function_exists('snappy_compress')) {
    $largeWriters['snappy_compress'] = [new Snappy(), fn () => snappy_compress($large)];
}
foreach ($largeWriters as $name => [$algorithm, $write]) {
    $bytes = $write();
    emit("reads $name 65 MB", sha1($algorithm->decompress($bytes)) === sha1($large));
    unset($bytes);
}
unset($large);

// 6. Levels, constructors and arguments --------------------------------------------------------

foreach ([[Brotli::class, fn () => new Brotli()], [LZ4::class, fn () => new LZ4()], [Zstd::class, fn () => new Zstd()]] as [$class, $make]) {
    foreach ([-2, -1, 0, 1, 3, 11, 12, 13, 22, 23, 25, '5', 5.0, 5.5, '5.5', ' 5', '5 ', 'abc', null, true, false, [], PHP_INT_MAX, PHP_INT_MIN] as $level) {
        $algorithm = $make();
        $label = json_encode($level);
        emit("$class setLevel($label)", outcome(function () use ($algorithm, $level) {
            $algorithm->setLevel($level);

            return $algorithm->getLevel();
        }));
    }
}
foreach ([LZ4::class, Zstd::class] as $class) {
    foreach ([[], [5], ['7'], [7.0], [7.5], ['x'], [null], [true], [PHP_INT_MAX], [-5], [-1], [13], [23], [1 << 32], [-(1 << 32)], [1, 2]] as $args) {
        $label = json_encode($args);
        emit("new $class($label)", outcome(fn () => new $class(...$args)));
        emit("new $class($label) compress", outcome(fn () => (new $class(...$args))->compress($text)));
    }
    emit("new $class(level: 4)", outcome(fn () => (new $class(level: 4))->getLevel()));
    emit("new $class(levle: 4)", outcome(fn () => new $class(levle: 4)));
}
foreach ([Brotli::class, Deflate::class, GZIP::class, LZ4::class, Snappy::class, Zstd::class] as $class) {
    $algorithm = new $class();
    $stringable = new class () {
        public function __toString(): string
        {
            return 'stringable';
        }
    };
    foreach (['123' => 123, '1.5' => 1.5, 'true' => true, 'false' => false, 'null' => null, 'array' => [], 'stringable' => $stringable, 'object' => new stdClass()] as $label => $data) {
        emit("$class compress($label)", outcome(fn () => $algorithm->compress($data)));
        emit("$class decompress($label)", outcome(fn () => $algorithm->decompress($data)));
    }
    emit("$class compress()", outcome(fn () => $algorithm->compress()));
    emit("$class decompress()", outcome(fn () => $algorithm->decompress()));
    emit("$class compress(data:)", outcome(fn () => $algorithm->compress(data: 'named')));
    emit("$class compress(input:)", outcome(fn () => $algorithm->compress(input: 'named')));
    emit("$class compress(a, b)", outcome(fn () => $algorithm->compress('a', 'b')));
}
emit('Brotli setLevel()', outcome(fn () => (new Brotli())->setLevel()));
emit('Brotli modes', outcome(function () {
    $brotli = new Brotli();
    $out = [];
    foreach (['useTextMode', 'useFontMode', 'useGenericMode'] as $method) {
        $brotli->$method();
        $out[$method] = [print_r($brotli, true), bin2hex($brotli->compress('mode mode mode mode'))];
    }

    return $out;
}));

// 7. Names and negotiation ---------------------------------------------------------------------

$names = ['brotli', 'br', 'BR', 'Brotli', 'deflate', 'gzip', 'GZIP', 'GzIp', 'lz4', 'LZ4', 'snappy', 'xz', 'XZ', 'zstd', 'ZSTD', 'none', 'identity', '', ' gzip', 'gzip ', 'x-gzip', 'compress', '*', "gz\xffip", "gzip\0", 'İgzip', 123, 1.5, true, null, []];
foreach ($names as $name) {
    emit('fromName(' . json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE) . ')', outcome(fn () => Compression::fromName($name)));
}
emit('GZIP::fromName(zstd)', outcome(fn () => GZIP::fromName('zstd')));
emit('Zstd::fromAcceptEncoding(gzip)', outcome(fn () => Zstd::fromAcceptEncoding('gzip')));
emit('fromName()', outcome(fn () => Compression::fromName()));

$headers = [
    // From tests/compat/compression of the conversion (appwrite/appwrite#14308).
    'gzip;q=0, deflate', 'br', 'br;q=0.5', 'br;q=0.5, gzip;q=0.5', 'gzip;q=0.5, br;q=0.5', 'snappy;q=0.6, br;q=0.4',
    'gzip, deflate, br', 'gzip, deflate, br, zstd', 'zstd', 'identity', 'none', 'identity, gzip', 'none;q=0.5, gzip;q=0.4',
    'deflate;q=0.9, gzip;q=0.9', 'unknown', 'unknown, gzip', 'GZIP', 'Br', 'BR;Q=0.5, GZIP;Q=0.6',
    // Edge cases of PHP's parsing.
    '', '0', ' ', ',', ';', '*', '*;q=0.5', 'gzip;q=0', 'gzip;q=0.0', 'gzip;q=-1', 'gzip;q=1.5', 'gzip;q=abc', 'gzip;q=',
    'gzip; q=0.5, br; q=0.6', 'gzip;q= 0.5', 'gzip;q=.5, br;q=0.49', 'gzip;q=1e3, br', 'gzip;q=5e-1, br;q=0.4',
    'gzip;level=1;q=0.2', 'gzip;q=1;q=0', 'gzip;foo=bar', ' gzip , br ', "gzip\t,\tbr", "gzip\n", "\0gzip", 'gzip,,br',
    'x-gzip, gzip;q=0.1', 'deflate, gzip', 'lz4, snappy, xz', 'xz', 'snappy', "gz\xffip", 'gzip;q=0.001, br;q=0.0009',
    'gzip;q=0x1, br;q=0.9', 'gzip;q=1.0000001, br;q=1', 'br, br;q=0', 'identity;q=1, gzip;q=1', 'zstd;q=0, br;q=0, gzip;q=0',
    'gzip;q=nan, br;q=inf', 'gzip;q=INF', 'brotli', 'brotli;q=0.5, br;q=0.6',
];
$stringable = new class () {
    public function __toString(): string
    {
        return 'gzip';
    }
};
$supportedLists = [
    'default' => [],
    '[gzip]' => ['gzip'],
    '[br]' => ['br'],
    '[brotli]' => ['brotli'],
    '[zstd, brotli, gzip, deflate, none, identity]' => ['zstd', 'brotli', 'gzip', 'deflate', 'none', 'identity'],
    '[snappy, lz4, xz]' => ['snappy', 'lz4', 'xz'],
    '[GZIP]' => ['GZIP'],
    '{gzip: true, brotli: true}' => ['gzip' => true, 'brotli' => true],
    '{gzip: true, br: true}' => ['gzip' => true, 'br' => true],
    '{gzip: false, zstd: 1}' => ['gzip' => false, 'zstd' => 1],
    '{gzip: null, deflate: "0", br: "yes"}' => ['gzip' => null, 'deflate' => '0', 'brotli' => 'yes'],
    '{0: gzip, 2: brotli}' => [0 => 'gzip', 2 => 'brotli'],
    '{1: gzip, 0: brotli}' => [1 => 'gzip', 0 => 'brotli'],
    '[1, 2]' => [1, 2],
    '{"1": true}' => ['1' => true],
    '[null]' => [null],
    '[true]' => [true],
    '[1.5]' => [1.5],
    '[[nested]]' => [['nested']],
    '[stringable]' => [$stringable],
    '[gz\xffip]' => ["gz\xffip"],
    '{gzip: [], brotli: [1]}' => ['gzip' => [], 'brotli' => [1]],
];
foreach ($headers as $header) {
    foreach ($supportedLists as $label => $supported) {
        $case = 'fromAcceptEncoding(' . json_encode($header, JSON_INVALID_UTF8_SUBSTITUTE) . ", $label)";
        emit($case, outcome(fn () => Compression::fromAcceptEncoding($header, $supported)));
    }
}
foreach ([[123], [null], ['gzip', 'x'], ['gzip', null], [], ['gzip', [], 'extra']] as $args) {
    emit('fromAcceptEncoding(' . json_encode($args) . ')', outcome(fn () => Compression::fromAcceptEncoding(...$args)));
}

// 8. The classes, as reflection and the engine see them ---------------------------------------

/** A type as reflection prints it. */
function type(?ReflectionType $type): ?string
{
    return $type === null ? null : (string) $type;
}
foreach (CLASSES as $class) {
    $reflection = new ReflectionClass($class);
    $constants = [];
    foreach ($reflection->getReflectionConstants() as $constant) {
        $constants[] = [$constant->getName(), $constant->getValue(), Reflection::getModifierNames($constant->getModifiers()), $constant->getDeclaringClass()->getName(), $constant->isFinal(), type($constant->getType())];
    }
    $properties = [];
    foreach ($reflection->getProperties() as $property) {
        $properties[] = [
            $property->getName(),
            Reflection::getModifierNames($property->getModifiers()),
            type($property->getType()),
            $property->hasDefaultValue(),
            $property->getDefaultValue(),
            $property->isPromoted(),
            $property->getDeclaringClass()->getName(),
        ];
    }
    $methods = [];
    foreach ($reflection->getMethods() as $method) {
        $params = [];
        foreach ($method->getParameters() as $param) {
            $params[] = [
                $param->getName(),
                type($param->getType()),
                $param->isOptional(),
                $param->isDefaultValueAvailable() ? $param->getDefaultValue() : 'no default',
                $param->isPromoted(),
                $param->allowsNull(),
                $param->isVariadic(),
                $param->isPassedByReference(),
            ];
        }
        $methods[] = [
            $method->getName(),
            $method->getDeclaringClass()->getName(),
            Reflection::getModifierNames($method->getModifiers()),
            $params,
            type($method->getReturnType()),
            $method->getNumberOfRequiredParameters(),
            $method->returnsReference(),
        ];
    }
    emit("reflection $class", [
        'parent' => get_parent_class($class),
        'abstract' => $reflection->isAbstract(),
        'final' => $reflection->isFinal(),
        'instantiable' => $reflection->isInstantiable(),
        'cloneable' => $reflection->isCloneable(),
        'interfaces' => $reflection->getInterfaceNames(),
        'constants' => $constants,
        'defaults' => $reflection->getDefaultProperties(),
        'properties' => $properties,
        'methods' => $methods,
        'constructor' => $reflection->getConstructor()?->getName(),
    ]);
}
foreach (ALGORITHMS as $class) {
    $algorithm = new $class();
    emit("$class basics", outcome(fn () => [
        $algorithm->getName(),
        $algorithm->getContentEncoding(),
        $class::isSupported(),
        method_exists($algorithm, 'getLevel') ? $algorithm->getLevel() : null,
        $algorithm instanceof Compression,
        print_r($algorithm, true),
        var_export($algorithm, true),
        json_encode($algorithm),
        bin2hex(serialize($algorithm)),
        (array) $algorithm,
        get_object_vars($algorithm),
        $algorithm == new $class(),
        $algorithm == clone $algorithm,
        unserialize(serialize($algorithm)) == $algorithm,
        is_callable([$class, 'isSupported']),
    ]));
}
emit('new Compression', outcome(fn () => new Compression()));
emit('Compression::isSupported()', outcome(fn () => Compression::isSupported()));
emit('Compression::NONE', [Compression::NONE, Compression::IDENTITY, Zstd::ZSTD, GZIP::BROTLI]);
emit('unserialize Zstd level 7', outcome(fn () => unserialize('O:34:"Utopia\\Compression\\Algorithms\\Zstd":1:{s:8:"' . "\0*\0" . 'level";i:7;}')->compress($text)));
emit('unserialize LZ4 level "x"', outcome(fn () => unserialize('O:33:"Utopia\\Compression\\Algorithms\\LZ4":1:{s:8:"' . "\0*\0" . 'level";s:1:"x";}')));
emit('static closure isSupported', outcome(fn () => array_map(fn (string $class) => $class::isSupported(), ALGORITHMS)));

// 9. PHP subclasses ------------------------------------------------------------------------------

class Reverse extends Compression
{
    public function getName(): string
    {
        return 'Re-Verse';
    }

    public function compress(string $data): string
    {
        return strrev($data);
    }

    public function decompress(string $data): string
    {
        return strrev($data);
    }

    public static function isSupported(): bool
    {
        return true;
    }
}
class NamedGzip extends GZIP
{
    public function getName(): string
    {
        return 'GZip-Named';
    }
}
class LevelOneBrotli extends Brotli
{
    public function getLevel(): int
    {
        return 1;
    }
}
class TextBrotli extends Brotli
{
    public function __construct(int $mode = BROTLI_TEXT_MODE, int $level = 5)
    {
        $this->mode = $mode;
        $this->level = $level;
    }
}
const BROTLI_TEXT_MODE = 1;
class FiveZstd extends Zstd
{
    public function __construct()
    {
        parent::__construct(5);
    }

    public function compress(string $data): string
    {
        return 'Z' . parent::compress($data);
    }

    public function decompress(string $data): string
    {
        return parent::decompress(substr($data, 1));
    }
}
class UnconstructedZstd extends Zstd
{
    public function __construct()
    {
    }
}
class LooseLZ4 extends LZ4
{
    public function setLevel(int $level): void
    {
        $this->level = $level;
    }
}
class OverZstd extends Zstd
{
    public function __construct(int $level = 23)
    {
        $this->level = $level;
    }
}
class ChattyCompression extends Compression
{
    public function getName(): string
    {
        throw new RuntimeException('no name');
    }

    public function compress(string $data): string
    {
        return $data;
    }

    public function decompress(string $data): string
    {
        return $data;
    }

    public static function isSupported(): bool
    {
        return false;
    }
}
emit('Reverse', outcome(fn () => [(new Reverse())->getContentEncoding(), (new Reverse())->compress('abc'), Reverse::isSupported(), Reverse::fromName('gzip'), Reverse::NONE]));
emit('NamedGzip', outcome(fn () => [(new NamedGzip())->getName(), (new NamedGzip())->getContentEncoding(), (new NamedGzip())->compress($text) === gzencode($text), print_r(new NamedGzip(), true)]));
emit('LevelOneBrotli', outcome(fn () => [(new LevelOneBrotli())->compress($text), print_r(new LevelOneBrotli(), true)]));
emit('TextBrotli', outcome(fn () => (new TextBrotli())->compress($text)));
emit('TextBrotli font', outcome(fn () => (new TextBrotli(2))->compress($text)));
emit('TextBrotli mode 3', outcome(fn () => (new TextBrotli(3))->compress($text)));
emit('TextBrotli mode -1', outcome(fn () => (new TextBrotli(-1))->compress($text)));
emit('TextBrotli level 12', outcome(fn () => (new TextBrotli(0, 12))->compress($text)));
emit('TextBrotli level -1', outcome(fn () => (new TextBrotli(0, -1))->compress($text)));
emit('TextBrotli getLevel', outcome(fn () => (new TextBrotli(0, 99))->getLevel()));
emit('FiveZstd', outcome(function () use ($text) {
    $zstd = new FiveZstd();

    return [$zstd->getLevel(), $zstd->compress($text), $zstd->decompress($zstd->compress($text)) === $text, print_r($zstd, true)];
}));
emit('UnconstructedZstd compress', outcome(fn () => (new UnconstructedZstd())->compress('x')));
emit('UnconstructedZstd getLevel', outcome(fn () => (new UnconstructedZstd())->getLevel()));
emit('UnconstructedZstd print_r', outcome(fn () => print_r(new UnconstructedZstd(), true)));
emit('UnconstructedZstd decompress', outcome(fn () => (new UnconstructedZstd())->decompress(zstd_compress('x'))));
emit('LooseLZ4 level 20', outcome(function () use ($text) {
    $lz4 = new LooseLZ4();
    $lz4->setLevel(20);

    return $lz4->compress($text);
}));
emit('LooseLZ4 level -3', outcome(function () use ($text) {
    $lz4 = new LooseLZ4();
    $lz4->setLevel(-3);

    return $lz4->compress($text);
}));
emit('ChattyCompression', outcome(fn () => (new ChattyCompression())->getContentEncoding()));
emit('instanceof', outcome(fn () => [
    Compression::fromName('br') instanceof Brotli,
    Compression::fromAcceptEncoding('gzip') instanceof Compression,
    new NamedGzip() instanceof GZIP,
    is_subclass_of(new FiveZstd(), Compression::class),
    get_parent_class(new FiveZstd()),
    class_parents(new FiveZstd()),
]));
emit('OverZstd', outcome(fn () => (new OverZstd())->compress($text)));
emit('OverZstd negative', outcome(fn () => (new OverZstd(-(1 << 40)))->compress('abc')));

// 10. XZ, which only works with ext-xz loaded --------------------------------------------------

emit('XZ isSupported', XZ::isSupported());
emit('XZ compress', outcome(fn () => (new XZ())->compress('x')));
emit('XZ decompress', outcome(fn () => (new XZ())->decompress('x')));
// A function of the algorithms' namespace is called before the global one, as PHP resolves it.
eval('namespace Utopia\\Compression\\Algorithms; function xzdecode($data) { return $data === "false" ? false : ($data === "int" ? 7 : "namespaced " . $data); }');
emit('XZ decompress, namespaced xzdecode', outcome(fn () => (new XZ())->decompress('x')));
emit('XZ decompress, false', outcome(fn () => (new XZ())->decompress('false')));
emit('XZ decompress, int', outcome(fn () => (new XZ())->decompress('int')));
emit('XZ isSupported, namespaced xzdecode', XZ::isSupported());

// 11. Input that exhausts memory_limit, each in a process of its own --------------------------

/** What a process running `$code` (after the autoloader) under `memory_limit=64M` prints. */
function contained(string $code): string
{
    $script = 'require ' . var_export(__DIR__ . '/../../../vendor/autoload.php', true) . '; ' . $code;
    $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=64M -d display_errors=1 -d log_errors=0 -r ' . escapeshellarg($script) . ' 2>&1';
    $output = (string) shell_exec($command);

    // The first line: the file and line differ (waived below), the message must not.
    $first = explode("\n", trim($output))[0];

    return (string) preg_replace('/ in .+ on line \d+$/', ' in (file) on line (line)', $first);
}
$fatal = [
    'Zstd frame claiming 1 GB' => '(new Utopia\Compression\Algorithms\Zstd())->decompress(base64_decode(' . var_export(base64_encode(substr_replace(zstd_compress('x'), "\xe0" . pack('P', 1 << 30), 4, 2)), true) . '));',
    'Snappy length of 1 GB' => '(new Utopia\Compression\Algorithms\Snappy())->decompress("\x80\x80\x80\x80\x04abc");',
    'Zstd within the limit' => 'echo strlen((new Utopia\Compression\Algorithms\Zstd())->decompress(zstd_compress(str_repeat("a", 16 << 20))));',
    'LZ4 prefix of 1 GB' => 'var_dump(@(new Utopia\Compression\Algorithms\LZ4())->decompress(pack("V", 1 << 30) . "\x10abcd"));',
];
foreach ($fatal as $case => $code) {
    emit("contained $case", contained($code));
}

// The known differences (crates/extension/README.md), asserted --------------------------------

$waivers = [
    // A warning names the caller's file and line: the classes have no PHP source.
    'warning file' => function () use ($internal) {
        $file = null;
        set_error_handler(function (int $level, string $message, string $at) use (&$file): bool {
            $file = $at;

            return true;
        });
        (new GZIP())->decompress('garbage');
        restore_error_handler();

        return ($file === __FILE__) === $internal;
    },
    // A closure cannot be bound to the scope of an internal class (PHP warns and returns null),
    // as one can to the PHP classes'.
    'closure scope' => function () use ($internal) {
        set_error_handler(fn (): bool => true);
        $bound = Closure::bind(fn () => 1, null, Zstd::class);
        restore_error_handler();

        return ($bound === null) === $internal;
    },
    // So does an exception.
    'exception file' => function () use ($internal) {
        try {
            (new Zstd())->setLevel(99);
        } catch (InvalidArgumentException $e) {
            return ($e->getFile() === __FILE__) === $internal;
        }

        return 'no exception';
    },
];
foreach ($waivers as $case => $run) {
    emit('waiver ' . $case, outcome($run));
}
