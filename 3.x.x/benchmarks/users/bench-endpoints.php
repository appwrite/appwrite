#!/usr/bin/env php
<?php
/**
 * Multi-endpoint /v1/users benchmark: PHP vs Rust.
 *
 * Usage:
 *   PHP_DIRECT=http://172.x.x.x RUST_DIRECT=http://172.x.x.x \
 *   TRAEFIK=http://127.0.0.1 N=100 \
 *   php 3.x.x/benchmarks/users/bench-endpoints.php
 *
 * Bootstraps a console project + API key via PHP_DIRECT unless
 * PROJECT_ID and API_KEY are already set.
 */

declare(strict_types=1);

$n = (int) (getenv('N') ?: 100);
$warmup = (int) (getenv('WARMUP') ?: 5);
$host = getenv('HOST_HDR') ?: 'appwrite.test';
$phpDirect = getenv('PHP_DIRECT') ?: '';
$rustDirect = getenv('RUST_DIRECT') ?: '';
$traefik = getenv('TRAEFIK') ?: 'http://127.0.0.1';

if ($phpDirect === '') {
    fwrite(STDERR, "PHP_DIRECT is required\n");
    exit(1);
}

function http_json(
    string $method,
    string $url,
    array $headers = [],
    array|object|null $body = null,
    string $host = 'appwrite.test',
): array {
    $ch = curl_init($url);
    $hdrs = ['Host: ' . $host, 'Content-Type: application/json', 'Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdrs[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        throw new RuntimeException("curl failed: {$err} ({$method} {$url})");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerRaw = substr($raw, 0, $headerSize);
    $bodyRaw = substr($raw, $headerSize);
    $cookies = [];
    foreach (explode("\r\n", $headerRaw) as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $cookie = trim(substr($line, strlen('Set-Cookie:')));
            $pair = explode(';', $cookie, 2)[0];
            [$ck, $cv] = array_pad(explode('=', $pair, 2), 2, '');
            $cookies[$ck] = $cv;
        }
    }
    $json = null;
    if ($bodyRaw !== '' && $bodyRaw !== false) {
        $json = json_decode($bodyRaw, true);
    }
    return ['status' => $status, 'body' => $json, 'cookies' => $cookies, 'raw' => $bodyRaw];
}

function must_ok(array $res, string $ctx): void
{
    if ($res['status'] < 200 || $res['status'] >= 300) {
        $snippet = is_array($res['body']) ? json_encode($res['body']) : (string) $res['raw'];
        throw new RuntimeException("{$ctx} HTTP {$res['status']}: {$snippet}");
    }
}

function bootstrap(string $phpDirect, string $host): array
{
    $email = 'bench_' . uniqid('', true) . '@localhost.test';
    $password = 'password123';
    $userId = 'br' . bin2hex(random_bytes(6));
    $teamId = 'bt' . bin2hex(random_bytes(6));
    $projectId = 'bp' . bin2hex(random_bytes(6));
    $keyId = 'bk' . bin2hex(random_bytes(6));

    fwrite(STDERR, "Bootstrapping project via {$phpDirect} ...\n");
    must_ok(http_json('POST', "{$phpDirect}/v1/account", [
        'X-Appwrite-Project' => 'console',
    ], [
        'userId' => $userId,
        'email' => $email,
        'password' => $password,
        'name' => 'Bench Root',
    ], $host), 'create account');

    $session = http_json('POST', "{$phpDirect}/v1/account/sessions/email", [
        'X-Appwrite-Project' => 'console',
    ], [
        'email' => $email,
        'password' => $password,
    ], $host);
    must_ok($session, 'login');
    if (empty($session['cookies']['a_session_console'])) {
        throw new RuntimeException('missing a_session_console cookie');
    }
    $cookie = 'a_session_console=' . $session['cookies']['a_session_console'];

    http_json('POST', "{$phpDirect}/v1/teams", [
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ], [
        'teamId' => $teamId,
        'name' => 'Bench Team',
    ], $host); // 201 or 409 ok

    $project = http_json('POST', "{$phpDirect}/v1/projects", [
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ], [
        'projectId' => $projectId,
        'name' => 'Bench Project',
        'teamId' => $teamId,
        'region' => getenv('_APP_REGION') ?: 'default',
    ], $host);
    must_ok($project, 'create project');
    $pid = $project['body']['$id'];

    $key = http_json('POST', "{$phpDirect}/v1/projects/{$pid}/keys", [
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ], [
        'keyId' => $keyId,
        'name' => 'Bench Key',
        'scopes' => [
            'users.read',
            'users.write',
            'sessions.read',
            'sessions.write',
            'targets.read',
            'targets.write',
        ],
    ], $host);
    must_ok($key, 'create key');

    return ['projectId' => $pid, 'apiKey' => $key['body']['secret']];
}

$projectId = getenv('PROJECT_ID') ?: '';
$apiKey = getenv('API_KEY') ?: '';
if ($projectId === '' || $apiKey === '') {
    $creds = bootstrap($phpDirect, $host);
    $projectId = $creds['projectId'];
    $apiKey = $creds['apiKey'];
}

$auth = [
    'X-Appwrite-Project' => $projectId,
    'X-Appwrite-Key' => $apiKey,
];

fwrite(STDERR, "PROJECT_ID={$projectId}\n");

/**
 * @param callable():void $fn
 * @return array{ops:float,elapsed:float}
 */
function time_n(int $n, callable $fn): array
{
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fn();
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    return [
        'ops' => $n / max($elapsed, 1e-9),
        'elapsed' => $elapsed,
    ];
}

function ensure_fixture(string $base, array $auth, string $host): string
{
    $id = 'fix' . bin2hex(random_bytes(8));
    $phone = '+1555' . random_int(1000000, 9999999);
    must_ok(http_json('POST', "{$base}/v1/users", $auth, [
        'userId' => $id,
        'email' => "{$id}@bench.local",
        'password' => 'password123',
        'name' => 'Fixture',
        'phone' => $phone,
    ], $host), 'fixture create');
    must_ok(http_json('PATCH', "{$base}/v1/users/{$id}/prefs", $auth, [
        'prefs' => ['theme' => 'dark'],
    ], $host), 'fixture prefs');
    return $id;
}

/**
 * @return list<array{endpoint:string,ops:float,elapsed:float}>
 */
function bench_backend(string $label, string $base, array $auth, string $host, int $n, int $warmup): array
{
    echo "\n=== {$label} ({$base}) N={$n} ===\n";
    $fixture = ensure_fixture($base, $auth, $host);
    $results = [];

    $run = function (string $endpoint, callable $fn) use (&$results, $n, $warmup): void {
        for ($i = 0; $i < $warmup; $i++) {
            try {
                $fn();
            } catch (Throwable) {
            }
        }
        $stats = time_n($n, $fn);
        $results[] = ['endpoint' => $endpoint, 'ops' => $stats['ops'], 'elapsed' => $stats['elapsed']];
        printf(
            "%-18s ops_per_s=%8.2f  elapsed_s=%.4f\n",
            $endpoint,
            $stats['ops'],
            $stats['elapsed']
        );
    };

    $run('create_user', function () use ($base, $auth, $host): void {
        $id = 'c' . bin2hex(random_bytes(8));
        must_ok(http_json('POST', "{$base}/v1/users", $auth, [
            'userId' => $id,
            'email' => "{$id}@bench.local",
            'password' => 'password123',
            'name' => 'Bench',
        ], $host), 'create_user');
    });

    $run('get_user', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('GET', "{$base}/v1/users/{$fixture}", $auth, null, $host), 'get_user');
    });

    $run('list_users', function () use ($base, $auth, $host): void {
        must_ok(http_json('GET', "{$base}/v1/users", $auth, null, $host), 'list_users');
    });

    $run('update_name', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('PATCH', "{$base}/v1/users/{$fixture}/name", $auth, [
            'name' => 'Name' . random_int(1, 1_000_000),
        ], $host), 'update_name');
    });

    $run('update_email', function () use ($base, $auth, $host, $fixture): void {
        $id = 'e' . bin2hex(random_bytes(6));
        must_ok(http_json('PATCH', "{$base}/v1/users/{$fixture}/email", $auth, [
            'email' => "{$id}@bench.local",
        ], $host), 'update_email');
    });

    $run('update_prefs', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('PATCH', "{$base}/v1/users/{$fixture}/prefs", $auth, [
            'prefs' => ['k' => (string) random_int(1, 1_000_000)],
        ], $host), 'update_prefs');
    });

    $run('get_prefs', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('GET', "{$base}/v1/users/{$fixture}/prefs", $auth, null, $host), 'get_prefs');
    });

    $run('update_labels', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('PUT', "{$base}/v1/users/{$fixture}/labels", $auth, [
            'labels' => ['l' . random_int(1, 99999)],
        ], $host), 'update_labels');
    });

    $run('update_status', function () use ($base, $auth, $host, $fixture): void {
        static $on = true;
        $on = !$on;
        must_ok(http_json('PATCH', "{$base}/v1/users/{$fixture}/status", $auth, [
            'status' => $on,
        ], $host), 'update_status');
    });

    $run('create_session', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('POST', "{$base}/v1/users/{$fixture}/sessions", $auth, new stdClass(), $host), 'create_session');
    });

    $run('list_sessions', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('GET', "{$base}/v1/users/{$fixture}/sessions", $auth, null, $host), 'list_sessions');
    });

    $run('create_token', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('POST', "{$base}/v1/users/{$fixture}/tokens", $auth, [
            'length' => 32,
            'expire' => 60,
        ], $host), 'create_token');
    });

    $run('create_jwt', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('POST', "{$base}/v1/users/{$fixture}/jwts", $auth, [
            'duration' => 60,
        ], $host), 'create_jwt');
    });

    $run('create_target', function () use ($base, $auth, $host, $fixture): void {
        $tid = 't' . bin2hex(random_bytes(6));
        must_ok(http_json('POST', "{$base}/v1/users/{$fixture}/targets", $auth, [
            'targetId' => $tid,
            'providerType' => 'email',
            'identifier' => "{$tid}@bench.local",
        ], $host), 'create_target');
    });

    $targets = http_json('GET', "{$base}/v1/users/{$fixture}/targets", $auth, null, $host);
    must_ok($targets, 'list targets seed');
    $targetId = $targets['body']['targets'][0]['$id'] ?? null;
    if (is_string($targetId) && $targetId !== '') {
        $run('get_target', function () use ($base, $auth, $host, $fixture, $targetId): void {
            must_ok(http_json('GET', "{$base}/v1/users/{$fixture}/targets/{$targetId}", $auth, null, $host), 'get_target');
        });
    }

    $run('list_targets', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('GET', "{$base}/v1/users/{$fixture}/targets", $auth, null, $host), 'list_targets');
    });

    $run('list_memberships', function () use ($base, $auth, $host, $fixture): void {
        must_ok(http_json('GET', "{$base}/v1/users/{$fixture}/memberships", $auth, null, $host), 'list_memberships');
    });

    $run('delete_user', function () use ($base, $auth, $host): void {
        $id = 'd' . bin2hex(random_bytes(8));
        must_ok(http_json('POST', "{$base}/v1/users", $auth, [
            'userId' => $id,
            'email' => "{$id}@bench.local",
            'password' => 'password123',
            'name' => 'Del',
        ], $host), 'delete_user create');
        must_ok(http_json('DELETE', "{$base}/v1/users/{$id}", $auth, null, $host), 'delete_user');
    });

    return $results;
}

$backends = [
    'php_direct' => $phpDirect,
];
if ($rustDirect !== '') {
    $backends['rust_direct'] = $rustDirect;
}
$backends['traefik_rust'] = $traefik;

$all = [];
foreach ($backends as $label => $base) {
    $all[$label] = bench_backend($label, $base, $auth, $host, $n, $warmup);
}

// Summary table
$endpoints = array_map(static fn ($r) => $r['endpoint'], $all['php_direct']);
echo "\n=== Summary (ops/s, N={$n}) ===\n";
$headers = array_keys($all);
printf("%-18s", 'endpoint');
foreach ($headers as $h) {
    printf(" %14s", $h);
}
if (isset($all['php_direct'], $all['rust_direct'])) {
    printf(" %10s", 'rust/php');
}
echo "\n";
printf("%-18s", str_repeat('-', 18));
foreach ($headers as $h) {
    printf(" %14s", str_repeat('-', 14));
}
if (isset($all['php_direct'], $all['rust_direct'])) {
    printf(" %10s", str_repeat('-', 10));
}
echo "\n";

foreach ($endpoints as $i => $endpoint) {
    printf("%-18s", $endpoint);
    $phpOps = null;
    $rustOps = null;
    foreach ($headers as $h) {
        $ops = $all[$h][$i]['ops'] ?? 0.0;
        if ($h === 'php_direct') {
            $phpOps = $ops;
        }
        if ($h === 'rust_direct') {
            $rustOps = $ops;
        }
        printf(" %14.2f", $ops);
    }
    if ($phpOps !== null && $rustOps !== null && $phpOps > 0) {
        printf(" %10.2fx", $rustOps / $phpOps);
    }
    echo "\n";
}
