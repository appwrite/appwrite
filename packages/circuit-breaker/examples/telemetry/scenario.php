<?php

declare(strict_types=1);

use Utopia\CircuitBreaker\Adapter\Redis as RedisAdapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Adapter\OpenTelemetry;

require __DIR__ . '/../../vendor/autoload.php';

$duration = duration($argv);
$config = [
    'key' => getenv('BREAKER_SCENARIO_CACHE_KEY') ?: 'checkout-api',
    'minimumThroughput' => (int) (getenv('BREAKER_SCENARIO_THRESHOLD') ?: 5),
    'timeout' => (int) (getenv('BREAKER_SCENARIO_TIMEOUT') ?: 12),
    'successThreshold' => (int) (getenv('BREAKER_SCENARIO_SUCCESS_THRESHOLD') ?: 3),
    'prefix' => getenv('BREAKER_DEMO_REDIS_PREFIX') ?: 'breaker-demo:',
];

$telemetry = createTelemetry();
$breaker = createBreaker($telemetry, $config);
resetBreaker($config);

$start = time();
$lastReport = $start;
$lastCollect = 0;
$counts = [
    'calls' => 0,
    'dependency' => 0,
    'fallback' => 0,
    'short_circuit' => 0,
    'requested_success' => 0,
    'requested_failure' => 0,
];

while ((time() - $start) < $duration) {
    $elapsed = time() - $start;
    [$phase, $successRate, $latencyRange, $pace] = phase($elapsed, $duration);
    $mode = random_int(1, 100) <= $successRate ? 'success' : 'failure';
    $latency = random_int($latencyRange[0], $latencyRange[1]);
    $before = $breaker->getState()->value;

    $result = $breaker->call(
        open: static fn(): array => ['path' => 'fallback'],
        close: static fn(): array => dependency($mode, $latency),
        halfOpen: static fn(): array => dependency($mode, $latency),
    );

    $counts['calls']++;
    $counts[$mode === 'success' ? 'requested_success' : 'requested_failure']++;
    if (($result['path'] ?? '') === 'dependency') {
        $counts['dependency']++;
    }
    if (($result['path'] ?? '') === 'fallback') {
        $counts['fallback']++;
    }
    if ($before === 'open') {
        $counts['short_circuit']++;
    }

    if (time() !== $lastCollect) {
        $telemetry->collect();
        $lastCollect = time();
    }

    if (time() - $lastReport >= 30) {
        printf(
            "[%3ds] %-18s state=%-9s calls=%3d dependency=%3d fallback=%3d short=%3d failures=%d successes=%d\n",
            time() - $start,
            $phase,
            $breaker->getState()->value,
            $counts['calls'],
            $counts['dependency'],
            $counts['fallback'],
            $counts['short_circuit'],
            $breaker->getFailureCount(),
            $breaker->getSuccessCount(),
        );
        $lastReport = time();
    }

    usleep($pace + random_int(-90000, 90000));
}

$telemetry->collect();
printf(
    "[done] state=%s calls=%d dependency=%d fallback=%d short=%d requested_success=%d requested_failure=%d\n",
    $breaker->getState()->value,
    $counts['calls'],
    $counts['dependency'],
    $counts['fallback'],
    $counts['short_circuit'],
    $counts['requested_success'],
    $counts['requested_failure'],
);

function createTelemetry(): Telemetry
{
    $endpoint = getenv('BREAKER_OTEL_ENDPOINT') ?: '';

    if ($endpoint === '') {
        return new NoTelemetry();
    }

    return new OpenTelemetry(
        $endpoint,
        'breaker',
        'circuit-breaker-demo',
        (gethostname() ?: 'local') . '-scenario-' . getmypid(),
    );
}

/**
 * @param array{key: string, minimumThroughput: int, timeout: int, successThreshold: int, prefix: string} $config
 */
function createBreaker(Telemetry $telemetry, array $config): CircuitBreaker
{
    return new CircuitBreaker(
        minimumThroughput: $config['minimumThroughput'],
        timeout: $config['timeout'],
        successThreshold: $config['successThreshold'],
        cache: new RedisAdapter(redis(), $config['prefix']),
        key: $config['key'],
        telemetry: $telemetry,
    );
}

/**
 * @param array{key: string, minimumThroughput: int, timeout: int, successThreshold: int, prefix: string} $config
 */
function resetBreaker(array $config): void
{
    $adapter = new RedisAdapter(redis(), $config['prefix']);

    foreach (['state', 'failures', 'successes', 'opened_at'] as $field) {
        $adapter->delete($config['key'] . ':' . $field);
    }
}

function redis(): object
{
    if (!class_exists('Redis')) {
        throw new RuntimeException('The redis extension is required for the telemetry scenario.');
    }

    $redisClass = 'Redis';
    $redis = new $redisClass();
    $host = getenv('BREAKER_REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('BREAKER_REDIS_PORT') ?: 6379);

    if (!$redis->connect($host, $port, 2.0)) {
        throw new RuntimeException(sprintf('Redis is not reachable at %s:%d.', $host, $port));
    }

    return $redis;
}

function dependency(string $mode, int $latency): array
{
    usleep($latency * 1000);

    if ($mode === 'failure') {
        throw new RuntimeException('Simulated checkout dependency failure.');
    }

    return ['path' => 'dependency'];
}

/**
 * @return array{string, int, array{int, int}, int}
 */
function phase(int $elapsed, int $duration): array
{
    $ratio = $duration > 0 ? $elapsed / $duration : 1;

    return match (true) {
        $ratio < 0.15 => ['warmup', 98, [25, 70], 450000],
        $ratio < 0.35 => ['brownout', 72, [90, 260], 420000],
        $ratio < 0.55 => ['downstream outage', 0, [80, 180], 360000],
        $ratio < 0.77 => ['recovery', 94, [45, 160], 420000],
        default => ['steady state', 99, [25, 85], 460000],
    };
}

/**
 * @param list<string> $argv
 */
function duration(array $argv): int
{
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--duration=')) {
            return max(30, (int) substr($argument, 11));
        }
    }

    return 300;
}
