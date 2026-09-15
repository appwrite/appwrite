#!/usr/bin/env php
<?php
/**
 * Concurrent /v1/users benchmark: PHP vs Rust (curl_multi).
 *
 * Usage:
 *   PHP_DIRECT=http://... RUST_DIRECT=http://... CONCURRENCY=50 N=200 \
 *   php bench-concurrent.php
 */

declare(strict_types=1);

// Suppress curl_close deprecation noise on PHP 8.5.
error_reporting(E_ALL & ~E_DEPRECATED);

$n = (int) (getenv('N') ?: 200);
$concurrency = (int) (getenv('CONCURRENCY') ?: 50);
$host = getenv('HOST_HDR') ?: 'appwrite.test';
$phpDirect = getenv('PHP_DIRECT') ?: '';
$rustDirect = getenv('RUST_DIRECT') ?: '';

if ($phpDirect === '' || $rustDirect === '') {
    fwrite(STDERR, "PHP_DIRECT and RUST_DIRECT are required\n");
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
        throw new RuntimeException(curl_error($ch));
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
    return [
        'status' => $status,
        'body' => $bodyRaw !== '' ? json_decode($bodyRaw, true) : null,
        'cookies' => $cookies,
        'raw' => $bodyRaw,
    ];
}

function must_ok(array $res, string $ctx): void
{
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException("{$ctx} HTTP {$res['status']}: {$res['raw']}");
    }
}

function bootstrap(string $phpDirect, string $host): array
{
    $email = 'cbench_' . uniqid('', true) . '@localhost.test';
    $password = 'password123';
    $userId = 'cr' . bin2hex(random_bytes(6));
    $teamId = 'ct' . bin2hex(random_bytes(6));
    $projectId = 'cp' . bin2hex(random_bytes(6));
    $keyId = 'ck' . bin2hex(random_bytes(6));

    must_ok(http_json('POST', "{$phpDirect}/v1/account", [
        'X-Appwrite-Project' => 'console',
    ], [
        'userId' => $userId,
        'email' => $email,
        'password' => $password,
        'name' => 'Conc Bench',
    ], $host), 'account');

    $session = http_json('POST', "{$phpDirect}/v1/account/sessions/email", [
        'X-Appwrite-Project' => 'console',
    ], [
        'email' => $email,
        'password' => $password,
    ], $host);
    must_ok($session, 'login');
    $cookie = 'a_session_console=' . ($session['cookies']['a_session_console'] ?? '');

    http_json('POST', "{$phpDirect}/v1/teams", [
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ], [
        'teamId' => $teamId,
        'name' => 'Conc Team',
    ], $host);

    $project = http_json('POST', "{$phpDirect}/v1/projects", [
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ], [
        'projectId' => $projectId,
        'name' => 'Conc Project',
        'teamId' => $teamId,
        'region' => getenv('_APP_REGION') ?: 'default',
    ], $host);
    must_ok($project, 'project');
    $pid = $project['body']['$id'];

    $key = http_json('POST', "{$phpDirect}/v1/projects/{$pid}/keys", [
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ], [
        'keyId' => $keyId,
        'name' => 'Conc Key',
        'scopes' => ['users.read', 'users.write', 'sessions.read', 'sessions.write'],
    ], $host);
    must_ok($key, 'key');

    return ['projectId' => $pid, 'apiKey' => $key['body']['secret']];
}

/**
 * @param callable(int,string,array,string):array{method:string,url:string,body?:mixed} $make
 */
function concurrent_bench(
    string $label,
    string $base,
    array $auth,
    string $host,
    int $concurrency,
    int $requests,
    callable $make,
): array {
    $mh = curl_multi_init();
    $inflight = 0;
    $errors = 0;
    $next = 0;
    $start = microtime(true);

    $add = function () use (
        &$inflight,
        &$next,
        $mh,
        $requests,
        $make,
        $base,
        $auth,
        $host
    ): void {
        if ($next >= $requests) {
            return;
        }
        $spec = $make($next, $base, $auth, $host);
        $next++;
        $ch = curl_init($spec['url']);
        $hdrs = ['Host: ' . $host, 'Content-Type: application/json', 'Accept: application/json'];
        foreach ($auth as $k => $v) {
            $hdrs[] = "{$k}: {$v}";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $spec['method'],
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        if (array_key_exists('body', $spec)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($spec['body'], JSON_THROW_ON_ERROR));
        }
        curl_multi_add_handle($mh, $ch);
        $inflight++;
    };

    for ($i = 0; $i < min($concurrency, $requests); $i++) {
        $add();
    }

    do {
        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 300) {
                $errors++;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $inflight--;
            $add();
        }
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($inflight > 0);

    $elapsed = microtime(true) - $start;
    $ops = $requests / max($elapsed, 1e-9);
    printf(
        "%-22s ops_per_s=%8.2f  elapsed=%.3f  errors=%d\n",
        $label,
        $ops,
        $elapsed,
        $errors
    );
    curl_multi_close($mh);
    return ['ops' => $ops, 'elapsed' => $elapsed, 'errors' => $errors];
}

$creds = bootstrap($phpDirect, $host);
$auth = [
    'X-Appwrite-Project' => $creds['projectId'],
    'X-Appwrite-Key' => $creds['apiKey'],
];
fwrite(STDERR, "PROJECT_ID={$creds['projectId']} CONCURRENCY={$concurrency} N={$n}\n");

$fid = 'cf' . bin2hex(random_bytes(6));
must_ok(http_json('POST', "{$phpDirect}/v1/users", $auth, [
    'userId' => $fid,
    'email' => "{$fid}@bench.local",
    'password' => 'password123',
    'name' => 'Fixture',
], $host), 'fixture');

$cases = [
    'get_user' => static function (int $i, string $base, array $auth, string $host) use ($fid): array {
        return ['method' => 'GET', 'url' => "{$base}/v1/users/{$fid}"];
    },
    'list_users' => static function (int $i, string $base, array $auth, string $host): array {
        return ['method' => 'GET', 'url' => "{$base}/v1/users"];
    },
    'update_name' => static function (int $i, string $base, array $auth, string $host) use ($fid): array {
        return [
            'method' => 'PATCH',
            'url' => "{$base}/v1/users/{$fid}/name",
            'body' => ['name' => 'N' . $i],
        ];
    },
    'create_user' => static function (int $i, string $base, array $auth, string $host): array {
        $id = 'x' . bin2hex(pack('N', $i)) . bin2hex(random_bytes(4));
        return [
            'method' => 'POST',
            'url' => "{$base}/v1/users",
            'body' => [
                'userId' => $id,
                'email' => "{$id}@bench.local",
                'password' => 'password123',
                'name' => 'C',
            ],
        ];
    },
];

$results = [];
echo "\n=== Concurrent CONCURRENCY={$concurrency} N={$n} ===\n";
foreach (['php' => $phpDirect, 'rust' => $rustDirect] as $backend => $base) {
    foreach ($cases as $name => $make) {
        $results["{$backend}:{$name}"] = concurrent_bench(
            "{$backend}:{$name}",
            $base,
            $auth,
            $host,
            $concurrency,
            $n,
            $make
        );
    }
}

echo "\n=== rust/php concurrent ratios ===\n";
foreach (array_keys($cases) as $name) {
    $phpOps = $results["php:{$name}"]['ops'] ?? 0.0;
    $rustOps = $results["rust:{$name}"]['ops'] ?? 0.0;
    $ratio = $phpOps > 0 ? $rustOps / $phpOps : 0.0;
    printf("%-14s php=%8.2f  rust=%8.2f  rust/php=%.2fx\n", $name, $phpOps, $rustOps, $ratio);
}
