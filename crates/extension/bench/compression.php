<?php

// Times utopia-php/compression on whichever implementation loaded: each algorithm compressing
// and decompressing 100 B, 4 KB, 1 MB and 64 MB of text (and 1 MB of random bytes, as an image
// is), the way Storage, the HTTP response and the workers call it, plus the negotiation every
// response runs. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/compression.php . <utopia.so> 5`.
//
// Brotli's default level (11) is timed up to 1 MB; at 64 MB it is timed at level 4, the level
// the HTTP response uses. GZIP and Deflate have no level to set.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Compression\Algorithms\Brotli;
use Utopia\Compression\Algorithms\Deflate;
use Utopia\Compression\Algorithms\GZIP;
use Utopia\Compression\Algorithms\LZ4;
use Utopia\Compression\Algorithms\Snappy;
use Utopia\Compression\Algorithms\Zstd;
use Utopia\Compression\Compression;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Compression::class))->isInternal();
$requested = in_array('compression', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' compression, but the other implementation loaded' . PHP_EOL);
    exit(1);
}
ini_set('memory_limit', '-1');

/** `$size` bytes of text: words of a fixed vocabulary in random order, about 3:1 under gzip. */
function text(int $size): string
{
    $randomizer = new Random\Randomizer(new Random\Engine\Xoshiro256StarStar(42));
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

$sizes = ['100 B' => 100, '4 KB' => 4096, '1 MB' => 1 << 20, '64 MB' => 64 << 20];
$inputs = array_map(text(...), $sizes);
$inputs['1 MB random'] = (new Random\Randomizer(new Random\Engine\Xoshiro256StarStar(7)))->getBytes(1 << 20);

$brotli4 = new Brotli();
$brotli4->setLevel(4);
$algorithms = [
    'GZIP' => [new GZIP(), array_keys($inputs)],
    'Zstd 3' => [new Zstd(), array_keys($inputs)],
    'Zstd 19' => [new Zstd(19), array_keys($inputs)],
    'Deflate' => [new Deflate(), array_keys($inputs)],
    'Brotli 11' => [new Brotli(), ['100 B', '4 KB', '1 MB', '1 MB random']],
    'Brotli 4' => [$brotli4, array_keys($inputs)],
    'LZ4' => [new LZ4(), array_keys($inputs)],
    'Snappy' => [new Snappy(), array_keys($inputs)],
];

$cases = [];
foreach ($algorithms as $name => [$algorithm, $keys]) {
    foreach ($keys as $key) {
        $input = $inputs[$key];
        $compressed = $algorithm->compress($input);
        $cases["$name compress $key"] = fn () => $algorithm->compress($input);
        $cases["$name decompress $key"] = fn () => $algorithm->decompress($compressed);
    }
}
// As Storage runs it: a new algorithm for each file.
$file = $inputs['4 KB'];
$stored = (new Zstd())->compress($file);
$cases['new Zstd + decompress 4 KB'] = fn () => (new Zstd())->decompress($stored);
// As the HTTP response runs it, on every compressible response.
$cases['fromAcceptEncoding'] = fn () => Compression::fromAcceptEncoding('gzip, deflate, br, zstd', ['zstd', 'br', 'gzip', 'deflate']);
$cases['fromAcceptEncoding default'] = fn () => Compression::fromAcceptEncoding('gzip;q=0.8, br;q=0.9, *;q=0.1');
$cases['fromName'] = fn () => Compression::fromName('gzip');
$cases['getContentEncoding'] = fn () => (new GZIP())->getContentEncoding();

// BENCH_FILTER, a regular expression, times only the operations it matches.
$filter = getenv('BENCH_FILTER') ?: '';
if ($filter !== '') {
    $cases = array_filter($cases, fn (string $name): bool => preg_match('/' . $filter . '/', $name) === 1, ARRAY_FILTER_USE_KEY);
}
$out = [];
foreach ($cases as $name => $fn) {
    // The first call warms up and sizes the timing: about 50 ms of calls, five times, the
    // median kept. A call of a second or more is its own sample.
    $t = hrtime(true);
    $fn();
    $first = hrtime(true) - $t;
    if ($first >= 1e9) {
        $out[$name] = $first;
        continue;
    }
    $n = max(1, (int) ceil(50e6 / max(1, $first)));
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
