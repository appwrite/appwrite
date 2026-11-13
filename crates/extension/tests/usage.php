<?php

// Utopia\Usage\Engine, without Composer: the transport contract and, under Swoole,
// that a request suspends its coroutine and nothing else.
//
//   _APP_EXTENSION_LIBRARIES=usage php -d extension=utopia.so crates/extension/tests/usage.php

declare(strict_types=1);

use Utopia\Usage\Engine;

$checks = 0;
$check = function (bool $ok, string $what) use (&$checks): void {
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAILED: {$what}\n");
        exit(1);
    }
};

if (!class_exists(Engine::class, false)) {
    fwrite(STDERR, "Utopia\\Usage\\Engine is not registered: load the extension with _APP_EXTENSION_LIBRARIES=usage\n");
    exit(1);
}

$config = ['host' => 'clickhouse', 'username' => 'user', 'password' => 'secret', 'database' => 'db', 'namespace' => 'ns'];

// The transport is called with the URL, the headers and the body, and its answer is the response.
$seen = [];
$engine = new Engine($config, function (string $url, array $headers, string $body) use (&$seen): array {
    $seen[] = [$url, $headers, $body];

    return [200, '{"data":[{"ping":1,"version":"25.11","uptime":5}]}'];
});
$health = $engine->healthCheck();
$check($health['healthy'] === true, 'a 200 answer is healthy');
$check(count($seen) === 2 && $seen[0][0] === 'http://clickhouse:8123/', 'a ping and a version query, to the HTTP interface');
$check($seen[0][1]['X-ClickHouse-User'] === 'user' && $seen[0][1]['X-ClickHouse-Key'] === 'secret', 'credentials travel as headers');
$check($seen[0][1]['X-ClickHouse-Database'] === 'db', 'the database travels as a header');
$check(str_starts_with($seen[0][1]['Content-Type'], 'multipart/form-data; boundary='), 'a query is a multipart body');
$check(str_contains($seen[0][2], 'name="query"'), 'the statement is a part of the body');
$check($engine->getRequestCount() === 2, 'the requests are counted');

// A failed request: a negative status and the reason.
$engine = new Engine($config, fn (): array => [-1, 'connection refused']);
try {
    $engine->setup();
    $check(false, 'a failed request throws');
} catch (Exception $e) {
    $check(str_contains($e->getMessage(), 'connection refused'), 'the reason is in the message: ' . $e->getMessage());
}

// A status that is not 200 is the server refusing the statement.
$engine = new Engine($config, fn (): array => [500, 'Code: 62. DB::Exception: Syntax error']);
try {
    $engine->setup();
    $check(false, 'a 500 throws');
} catch (Exception $e) {
    $check(str_contains($e->getMessage(), 'HTTP 500') && str_contains($e->getMessage(), 'Syntax error'), 'the status and body are in the message');
}

// A transport that throws: the engine reports a failed request, and the thrown exception is not left pending.
$engine = new Engine($config, function (): array {
    throw new RuntimeException('boom');
});
try {
    $engine->setup();
    $check(false, 'a throwing transport throws');
} catch (Throwable $e) {
    $check(true, 'a throwing transport surfaces as ' . $e::class);
}
$check($engine->getRequestCount() === 1, 'the engine is usable after a throwing transport');

// Rows are refused with the PHP adapter's messages.
$engine = new Engine($config, fn (): array => [200, '']);
foreach ([
    [[['value' => 1]], "Metric #0: 'metric' is required"],
    [[['metric' => 'a']], "Metric #0: 'value' is required"],
    [[['metric' => 1, 'value' => 1]], "Metric #0: 'metric' must be a string, got integer"],
    [[['metric' => 'a', 'value' => '1']], "Metric #0: 'value' must be an integer, got string"],
    [[['metric' => 'a', 'value' => 1, 'tenant' => 5]], "Metric #0: 'tenant' must be a string or null, got integer"],
    [[['metric' => 'a', 'value' => -1]], 'Metric #0: Value cannot be negative'],
] as [$rows, $message]) {
    try {
        $engine->addBatch($rows, 'event', 1000);
        $check(false, "refused: {$message}");
    } catch (Exception $e) {
        $check($e->getMessage() === $message, "expected \"{$message}\", got \"{$e->getMessage()}\"");
    }
}
$check($engine->addBatch([], 'event', 1000) === true, 'an empty batch is written without a request');
$check($engine->addBatch([['metric' => 'a', 'value' => -1, 'allowNegative' => true, 'time' => new DateTime('2025-03-02 10:00:00.123')]], 'event', 1000), 'a signed delta and a DateTime are accepted');

// Under Swoole a request suspends its own coroutine: twenty requests that each wait 100 ms
// take about 200 ms together (a health check is two requests), where a blocked worker would take four seconds.
if (extension_loaded('swoole')) {
    $elapsed = 0.0;
    $healthy = 0;
    $order = [];
    Swoole\Coroutine\run(function () use ($config, &$elapsed, &$healthy, &$order): void {
        $engine = new Engine($config, function () use (&$order): array {
            $order[] = 'sent';
            Swoole\Coroutine::sleep(0.1);
            $order[] = 'answered';

            return [200, '{"data":[{"ping":1,"version":"25.11","uptime":5}]}'];
        });
        $started = microtime(true);
        $wait = new Swoole\Coroutine\WaitGroup();
        for ($i = 0; $i < 20; $i++) {
            $wait->add();
            Swoole\Coroutine::create(function () use ($engine, $wait, &$healthy): void {
                $healthy += $engine->healthCheck()['healthy'] === true ? 1 : 0;
                $wait->done();
            });
        }
        $wait->wait();
        $elapsed = microtime(true) - $started;
    });
    $check($healthy === 20, 'every coroutine got its answer');
    $check(array_slice($order, 0, 20) === array_fill(0, 20, 'sent'), 'every coroutine sent its first request before any was answered');
    $check($elapsed < 1.0, sprintf('twenty 100 ms requests ran together (%.0f ms)', $elapsed * 1000));
    echo sprintf("swoole: 20 concurrent requests in %.0f ms\n", $elapsed * 1000);
} else {
    echo "swoole is not loaded: the coroutine check did not run\n";
}

echo "ok: {$checks} checks\n";
