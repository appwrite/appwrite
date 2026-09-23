<?php

declare(strict_types=1);

use Utopia\CircuitBreaker\Adapter\Redis as RedisAdapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\CircuitState;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;
use Utopia\Telemetry\Adapter\OpenTelemetry;

require __DIR__ . '/../../vendor/autoload.php';

$telemetry = createTelemetry();

try {
    route($telemetry);
} finally {
    $telemetry->collect();
}

function route(Telemetry $telemetry): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'OPTIONS') {
        cors();
        http_response_code(204);
        return;
    }

    if ($path === '/') {
        serveFile(__DIR__ . '/public/index.html', 'text/html; charset=utf-8');
        return;
    }

    if ($path === '/app.js') {
        serveFile(__DIR__ . '/public/app.js', 'application/javascript; charset=utf-8');
        return;
    }

    if ($path === '/styles.css') {
        serveFile(__DIR__ . '/public/styles.css', 'text/css; charset=utf-8');
        return;
    }

    if ($path === '/favicon.ico') {
        http_response_code(204);
        return;
    }

    if ($path === '/api/status' && $method === 'GET') {
        $options = breakerOptions($_GET);
        jsonResponse(statusPayload(createBreaker($telemetry, $options), $options));
        return;
    }

    if ($path === '/api/call' && $method === 'POST') {
        $payload = requestPayload();
        $options = breakerOptions($payload);
        $breaker = createBreaker($telemetry, $options);
        $mode = is_string($payload['mode'] ?? null) ? $payload['mode'] : 'success';
        $latency = max(0, min(2000, (int) ($payload['latency'] ?? 80)));

        $call = performCall($breaker, $mode, $latency);
        jsonResponse(statusPayload($breaker, $options) + ['result' => $call['result'], 'call' => $call]);
        return;
    }

    if ($path === '/api/burst' && $method === 'POST') {
        $payload = requestPayload();
        $options = breakerOptions($payload);
        $calls = max(1, min(50, (int) ($payload['calls'] ?? 10)));
        $failureRate = max(0, min(100, (int) ($payload['failureRate'] ?? 35)));
        $latency = max(0, min(1000, (int) ($payload['latency'] ?? 40)));
        $breaker = createBreaker($telemetry, $options);
        $results = [];

        for ($i = 0; $i < $calls; $i++) {
            $mode = random_int(1, 100) <= $failureRate ? 'failure' : 'success';
            $results[] = performCall($breaker, $mode, $latency);
        }

        jsonResponse(statusPayload($breaker, $options) + ['results' => $results]);
        return;
    }

    if ($path === '/api/reset' && $method === 'POST') {
        $options = breakerOptions(requestPayload());
        resetBreaker($options);
        jsonResponse(statusPayload(createBreaker($telemetry, $options), $options));
        return;
    }

    jsonResponse(['error' => 'Not found'], 404);
}

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
        (gethostname() ?: 'local') . '-' . getmypid(),
    );
}

/**
 * @param array{minimumThroughput: int, timeout: int, successThreshold: int, key: string, prefix: string} $options
 */
function createBreaker(Telemetry $telemetry, array $options): CircuitBreaker
{
    return new CircuitBreaker(
        minimumThroughput: $options['minimumThroughput'],
        timeout: $options['timeout'],
        successThreshold: $options['successThreshold'],
        cache: new RedisAdapter(redis(), $options['prefix']),
        key: $options['key'],
        telemetry: $telemetry,
    );
}

/**
 * @return array{mode: string, before: string, after: string, path: string, result: array<string, string>}
 */
function performCall(CircuitBreaker $breaker, string $mode, int $latency): array
{
    $before = $breaker->getState()->value;
    $result = $breaker->call(
        open: static fn(): array => ['path' => 'fallback', 'message' => 'fallback response'],
        close: static fn(): array => runDependency($mode, $latency, 'closed'),
        halfOpen: static fn(): array => runDependency($mode, $latency, 'half_open'),
    );
    $after = $breaker->getState()->value;

    return [
        'mode' => $mode,
        'before' => $before,
        'after' => $after,
        'path' => is_string($result['path'] ?? null) ? $result['path'] : 'unknown',
        'result' => $result,
    ];
}

function runDependency(string $mode, int $latency, string $state): array
{
    if ($latency > 0) {
        usleep($latency * 1000);
    }

    if ($mode === 'failure') {
        throw new RuntimeException('Simulated dependency failure.');
    }

    return ['path' => 'dependency', 'state' => $state, 'message' => 'dependency response'];
}

/**
 * @param array{minimumThroughput: int, timeout: int, successThreshold: int, key: string, prefix: string} $options
 */
function statusPayload(CircuitBreaker $breaker, array $options): array
{
    $state = $breaker->getState();
    $openedAt = openedAt($options);
    $nextRetryIn = $state === CircuitState::OPEN && $openedAt !== null
        ? max(0, $options['timeout'] - (time() - $openedAt))
        : 0;

    return [
        'state' => $state->value,
        'stateLabel' => stateLabel($state),
        'failures' => $breaker->getFailureCount(),
        'successes' => $breaker->getSuccessCount(),
        'minimumThroughput' => $options['minimumThroughput'],
        'timeout' => $options['timeout'],
        'successThreshold' => $options['successThreshold'],
        'nextRetryIn' => $nextRetryIn,
        'config' => [
            'key' => $options['key'],
            'minimumThroughput' => $options['minimumThroughput'],
            'timeout' => $options['timeout'],
            'successThreshold' => $options['successThreshold'],
        ],
        'grafanaUrl' => getenv('BREAKER_GRAFANA_URL') ?: 'http://localhost:3030/d/circuit-breaker/circuit-breaker-telemetry',
        'prometheusUrl' => getenv('BREAKER_PROMETHEUS_URL') ?: 'http://localhost:9090',
        'timestamp' => time(),
    ];
}

function stateLabel(CircuitState $state): string
{
    return match ($state) {
        CircuitState::CLOSED => 'Closed',
        CircuitState::OPEN => 'Open',
        CircuitState::HALF_OPEN => 'Half-open',
    };
}

/**
 * @param array{minimumThroughput: int, timeout: int, successThreshold: int, key: string, prefix: string} $options
 */
function resetBreaker(array $options): void
{
    $adapter = new RedisAdapter(redis(), $options['prefix']);
    $key = $options['key'];

    foreach (['state', 'failures', 'successes', 'opened_at'] as $field) {
        $adapter->delete($key . ':' . $field);
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array{minimumThroughput: int, timeout: int, successThreshold: int, key: string, prefix: string}
 */
function breakerOptions(array $payload): array
{
    return [
        'minimumThroughput' => intOption($payload, 'minimumThroughput', (int) (getenv('BREAKER_DEMO_THRESHOLD') ?: 3), 1, 20),
        'timeout' => intOption($payload, 'timeout', (int) (getenv('BREAKER_DEMO_TIMEOUT') ?: 8), 1, 120),
        'successThreshold' => intOption($payload, 'successThreshold', (int) (getenv('BREAKER_DEMO_SUCCESS_THRESHOLD') ?: 2), 1, 10),
        'key' => normalizeKey($payload['key'] ?? getenv('BREAKER_DEMO_CACHE_KEY') ?: 'local-api'),
        'prefix' => getenv('BREAKER_DEMO_REDIS_PREFIX') ?: 'breaker-demo:',
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function intOption(array $payload, string $key, int $default, int $min, int $max): int
{
    if (!isset($payload[$key]) || !is_numeric($payload[$key])) {
        return $default;
    }

    return max($min, min($max, (int) $payload[$key]));
}

function normalizeKey(mixed $value): string
{
    $key = is_scalar($value) ? (string) $value : 'local-api';
    $key = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $key) ?? 'local-api';
    $key = trim($key, '-_.:');

    return substr($key !== '' ? $key : 'local-api', 0, 64);
}

/**
 * @param array{minimumThroughput: int, timeout: int, successThreshold: int, key: string, prefix: string} $options
 */
function openedAt(array $options): ?int
{
    $adapter = new RedisAdapter(redis(), $options['prefix']);
    $value = $adapter->get($options['key'] . ':opened_at');

    return is_numeric($value) ? (int) $value : null;
}

function redis(): object
{
    static $redis = null;

    if (is_object($redis) && method_exists($redis, 'isConnected') && $redis->isConnected()) {
        return $redis;
    }

    if (!class_exists('Redis')) {
        throw new RuntimeException('The redis extension is required for the telemetry demo server.');
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

function requestPayload(): array
{
    $body = file_get_contents('php://input');
    $payload = is_string($body) && $body !== '' ? json_decode($body, true) : [];

    return is_array($payload) ? $payload : [];
}

function serveFile(string $path, string $contentType): void
{
    if (!is_file($path)) {
        http_response_code(404);
        return;
    }

    header('Content-Type: ' . $contentType);
    readfile($path);
}

function jsonResponse(array $payload, int $status = 200): void
{
    cors();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}

function cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
