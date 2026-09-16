<?php

/**
 * Codec benchmark: what each codec costs, alone and once Redis carries it.
 *
 * The first rows time the codec by itself, so the encode/decode cost is visible
 * without a network round trip in front of it. The rest drive Cache::save() and
 * Cache::load() through a real adapter, which is the number that matters: a
 * codec that is twice as fast in isolation may move an adapter's throughput by
 * a few percent once Redis is on the wire. Payload size is reported alongside,
 * because bytes are what the cache server stores and ships.
 *
 * Prints one whitespace-separated row per cell:
 *
 *   <adapter> <codec> <payload> <bytes> <save ops/s> <load ops/s>
 *
 * Environment: REDIS_HOST, REDIS_PORT, ITERATIONS, REPEAT.
 */

declare(strict_types=1);

use Utopia\Cache\Adapter;
use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Cache\Codec;
use Utopia\Cache\Codec\Igbinary;
use Utopia\Cache\Codec\Json;

require __DIR__ . '/../../vendor/autoload.php';

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 16381);
$iterations = (int) (getenv('ITERATIONS') ?: 2000);
$repeat = (int) (getenv('REPEAT') ?: 3);

/**
 * A document shaped like what Appwrite caches: string ids, timestamps, a
 * permissions list, nested attributes. `small` is one; `large` is a page of them.
 *
 * @return array<string, mixed>
 */
$document = static fn(int $i): array => [
    '$id' => str_pad((string) $i, 20, '0', STR_PAD_LEFT),
    '$createdAt' => '2026-09-16T10:00:00.000+00:00',
    '$updatedAt' => '2026-09-16T10:00:00.000+00:00',
    '$permissions' => ['read("any")', 'update("user:' . $i . '")', 'delete("user:' . $i . '")'],
    'title' => 'Document ' . $i,
    'body' => str_repeat('lorem ipsum dolor sit amet ', 8),
    'tags' => ['alpha', 'beta', 'gamma'],
    'score' => $i * 1.5,
    'published' => $i % 2 === 0,
    'metadata' => ['views' => $i * 10, 'empty' => new stdClass()],
];

$payloads = [
    'small' => $document(1),
    'large' => ['total' => 50, 'documents' => array_map($document, range(1, 50))],
];

$codecs = [
    'json' => new Json(),
    'igbinary' => new Igbinary(),
];

/**
 * Median ops/s over $repeat runs of $iterations calls.
 *
 * @param  callable(): void  $operation
 */
$measure = static function (callable $operation) use ($iterations, $repeat): float {
    $samples = [];
    for ($run = 0; $run < $repeat; $run++) {
        $started = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $operation();
        }

        $samples[] = $iterations / ((hrtime(true) - $started) / 1_000_000_000);
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
};

$row = static function (string $adapter, string $codec, string $payload, int $bytes, float $save, float $load): void {
    printf("%s %s %s %d %.0f %.0f\n", $adapter, $codec, $payload, $bytes, $save, $load);
};

// No adapter: the pure encode/decode cost with nothing in front of it.
foreach ($payloads as $payloadName => $payload) {
    foreach ($codecs as $codecName => $codec) {
        $encoded = $codec->encode($payload);
        $row(
            'none',
            $codecName,
            $payloadName,
            strlen($encoded),
            $measure(static function () use ($codec, $payload): void {
                $codec->encode($payload);
            }),
            $measure(static function () use ($codec, $encoded): void {
                $codec->decode($encoded);
            }),
        );
    }
}

/**
 * Drive save() and load() through Cache, the way an application does.
 *
 * @param  callable(Codec): Adapter  $factory
 */
$benchAdapter = static function (string $name, callable $factory) use ($payloads, $codecs, $measure, $row): void {
    foreach ($payloads as $payloadName => $payload) {
        foreach ($codecs as $codecName => $codec) {
            $cache = new Cache($factory($codec));
            $key = "bench:{$codecName}:{$payloadName}";
            $cache->save($key, $payload, $key);

            $save = $measure(static function () use ($cache, $key, $payload): void {
                $cache->save($key, $payload, $key);
            });
            $load = $measure(static function () use ($cache, $key): void {
                $cache->load($key, 3600, $key);
            });

            $row($name, $codecName, $payloadName, strlen($codec->encode($payload)), $save, $load);
            $cache->purge($key);
        }
    }
};

if (extension_loaded('redis')) {
    $benchAdapter('redis', static function (Codec $codec) use ($host, $port): Adapter {
        $redis = new Redis();
        $redis->connect($host, $port);

        return new RedisAdapter($redis, $codec);
    });
}
