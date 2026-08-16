#!/usr/bin/env php
<?php

/**
 * End-to-end worker/scheduler mode benchmark.
 *
 * Measures idle + under-load memory for worker/scheduler containers, and
 * latency/throughput of workloads that exercise databases + deletes workers.
 *
 * Usage:
 *   php tests/benchmarks/workers/bench.php \
 *     --endpoint=http://localhost/v1 \
 *     --label=combined \
 *     --output=tests/benchmarks/workers/results/combined.json
 */

declare(strict_types=1);

$opts = getopt('', [
    'endpoint::',
    'label::',
    'output::',
    'users::',
    'attributes::',
    'documents::',
    'idle-seconds::',
    'sample-ms::',
]);

$endpoint = rtrim((string) ($opts['endpoint'] ?? getenv('APPWRITE_ENDPOINT') ?: 'http://localhost/v1'), '/');
$label = (string) ($opts['label'] ?? 'unnamed');
$output = (string) ($opts['output'] ?? ('tests/benchmarks/workers/results/' . $label . '.json'));
$userCount = max(1, (int) ($opts['users'] ?? 50));
$attributeCount = max(1, (int) ($opts['attributes'] ?? 20));
$documentCount = max(1, (int) ($opts['documents'] ?? 200));
$idleSeconds = max(1, (int) ($opts['idle-seconds'] ?? 10));
$sampleMs = max(200, (int) ($opts['sample-ms'] ?? 500));

$startedAt = microtime(true);
$samplesFile = sys_get_temp_dir() . '/appwrite-worker-bench-samples-' . getmypid() . '.jsonl';
@unlink($samplesFile);

function out(string $message): void
{
    fwrite(STDERR, '[' . date('H:i:s') . '] ' . $message . PHP_EOL);
}

function http(string $method, string $url, array $headers = [], ?array $body = null): array
{
    $ch = curl_init($url);
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_TIMEOUT => 120,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $rawBody = substr($raw, $headerSize);
    $json = json_decode($rawBody, true);

    return [
        'status' => $status,
        'headers' => $rawHeaders,
        'body' => is_array($json) ? $json : [],
        'raw' => $rawBody,
    ];
}

function assertStatus(array $response, array $ok, string $context): void
{
    if (!in_array($response['status'], $ok, true)) {
        throw new RuntimeException(sprintf(
            '%s failed with HTTP %d: %s',
            $context,
            $response['status'],
            substr($response['raw'], 0, 500)
        ));
    }
}

function parseMemToBytes(string $value): int
{
    $value = trim(explode('/', $value)[0]);
    if (!preg_match('/^([\d.]+)\s*([KMGT]?i?B)$/i', $value, $m)) {
        return 0;
    }
    $n = (float) $m[1];
    $unit = strtoupper($m[2]);
    $mult = match ($unit) {
        'B' => 1,
        'KB' => 1000,
        'KIB' => 1024,
        'MB' => 1000 ** 2,
        'MIB' => 1024 ** 2,
        'GB' => 1000 ** 3,
        'GIB' => 1024 ** 3,
        'TB' => 1000 ** 4,
        'TIB' => 1024 ** 4,
        default => 1,
    };

    return (int) round($n * $mult);
}

function isWorkerOrScheduler(string $name): bool
{
    return (bool) preg_match('/(^|-)(appwrite-worker|appwrite-task-scheduler)/', $name);
}

function sampleDockerStats(): array
{
    $cmd = "docker stats --no-stream --format '{{.Name}}\t{{.MemUsage}}\t{{.CPUPerc}}\t{{.MemPerc}}'";
    $lines = [];
    exec($cmd, $lines, $code);
    if ($code !== 0) {
        return ['containers' => [], 'workerMemBytes' => 0, 'workerCpuPct' => 0.0, 'containerCount' => 0];
    }

    $containers = [];
    $workerMem = 0;
    $workerCpu = 0.0;
    foreach ($lines as $line) {
        [$name, $mem, $cpu, $memPct] = array_pad(explode("\t", $line), 4, '0');
        if ($name === '' || !isWorkerOrScheduler($name)) {
            continue;
        }
        $bytes = parseMemToBytes($mem);
        $cpuVal = (float) rtrim($cpu, '%');
        $containers[] = [
            'name' => $name,
            'memBytes' => $bytes,
            'memHuman' => trim(explode('/', $mem)[0]),
            'cpuPct' => $cpuVal,
            'memPct' => (float) rtrim($memPct, '%'),
        ];
        $workerMem += $bytes;
        $workerCpu += $cpuVal;
    }

    usort($containers, static fn ($a, $b) => $b['memBytes'] <=> $a['memBytes']);

    return [
        'containers' => $containers,
        'workerMemBytes' => $workerMem,
        'workerCpuPct' => $workerCpu,
        'containerCount' => count($containers),
    ];
}

function uniqueId(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(8));
}

function cookieHeader(string $rawHeaders): string
{
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $rawHeaders, $matches);
    return implode('; ', $matches[1] ?? []);
}

function bytesToMiB(int $bytes): float
{
    return round($bytes / 1024 / 1024, 2);
}

function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $idx = (int) floor(($p / 100) * (count($values) - 1));
    return round($values[$idx], 3);
}

// --- background sampler (child writes JSONL; parent aggregates at end) ---
$appendSample = static function (string $file, array $snap): void {
    file_put_contents($file, json_encode([
        't' => microtime(true),
        'workerMemBytes' => $snap['workerMemBytes'],
        'workerCpuPct' => $snap['workerCpuPct'],
        'containerCount' => $snap['containerCount'],
        'containers' => $snap['containers'],
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
};

if (!function_exists('pcntl_fork')) {
    out('pcntl unavailable; sampling synchronously around phases only');
    $pid = -1;
} else {
    $pid = pcntl_fork();
    if ($pid === 0) {
        while (true) {
            $appendSample($samplesFile, sampleDockerStats());
            usleep($sampleMs * 1000);
        }
    }
    if ($pid < 0) {
        out('fork failed; sampling synchronously around phases only');
    }
}

$loadSamples = static function () use ($samplesFile): array {
    if (!is_file($samplesFile)) {
        return [];
    }
    $rows = [];
    foreach (file($samplesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
};

$samples = [];

try {
    out("Benchmark label={$label} endpoint={$endpoint}");
    out("Workload: attributes={$attributeCount} users={$userCount} documents={$documentCount}");

    // Idle baseline
    out("Sampling idle memory for {$idleSeconds}s…");
    $idleStart = microtime(true);
    if ($pid < 0) {
        while (microtime(true) - $idleStart < $idleSeconds) {
            $appendSample($samplesFile, sampleDockerStats());
            usleep($sampleMs * 1000);
        }
    } else {
        sleep($idleSeconds);
    }
    $idleSamples = array_values(array_filter(
        $loadSamples(),
        static fn ($s) => $s['t'] >= $idleStart && $s['t'] <= microtime(true)
    ));
    $idleMem = $idleSamples === [] ? 0 : (int) round(array_sum(array_column($idleSamples, 'workerMemBytes')) / count($idleSamples));
    $idleCount = $idleSamples[0]['containerCount'] ?? sampleDockerStats()['containerCount'];
    out(sprintf('Idle worker/scheduler memory: %.2f MiB across %d containers', bytesToMiB($idleMem), $idleCount));

    // Provision console project
    $email = uniqueId('bench') . '@appwrite.test';
    $password = 'Password123!';
    out('Provisioning console account/project…');
    $t0 = microtime(true);

    $account = http('POST', $endpoint . '/account', [
        'Content-Type' => 'application/json',
        'X-Appwrite-Project' => 'console',
    ], [
        'userId' => 'unique()',
        'email' => $email,
        'password' => $password,
        'name' => 'Worker Bench',
    ]);
    assertStatus($account, [201, 409], 'create account');

    $session = http('POST', $endpoint . '/account/sessions/email', [
        'Content-Type' => 'application/json',
        'X-Appwrite-Project' => 'console',
    ], [
        'email' => $email,
        'password' => $password,
    ]);
    assertStatus($session, [201], 'create session');
    $cookie = cookieHeader($session['headers']);
    $consoleHeaders = [
        'Content-Type' => 'application/json',
        'X-Appwrite-Project' => 'console',
        'Cookie' => $cookie,
    ];

    $team = http('POST', $endpoint . '/teams', $consoleHeaders, [
        'teamId' => 'unique()',
        'name' => 'Bench Team ' . uniqueId(),
    ]);
    assertStatus($team, [201], 'create team');
    $teamId = $team['body']['$id'];

    $project = http('POST', $endpoint . '/projects', $consoleHeaders, [
        'projectId' => 'unique()',
        'name' => 'Worker Bench ' . uniqueId(),
        'teamId' => $teamId,
    ]);
    assertStatus($project, [201], 'create project');
    $projectId = $project['body']['$id'];

    $key = http('POST', $endpoint . '/projects/' . $projectId . '/keys', $consoleHeaders, [
        'keyId' => 'unique()',
        'name' => 'bench',
        'scopes' => [
            'users.read', 'users.write',
            'databases.read', 'databases.write',
            'collections.read', 'collections.write',
            'attributes.read', 'attributes.write',
            'documents.read', 'documents.write',
            'health.read',
        ],
    ]);
    assertStatus($key, [201], 'create api key');
    $apiKey = $key['body']['secret'];
    $apiHeaders = [
        'Content-Type' => 'application/json',
        'X-Appwrite-Project' => $projectId,
        'X-Appwrite-Key' => $apiKey,
    ];
    $provisionMs = (microtime(true) - $t0) * 1000;
    out(sprintf('Provisioned project %s in %.0f ms', $projectId, $provisionMs));

    // Phase 1: attributes → databases worker
    out("Phase 1: create {$attributeCount} attributes and wait until available…");
    $db = http('POST', $endpoint . '/databases', $apiHeaders, [
        'databaseId' => 'unique()',
        'name' => 'Bench DB',
    ]);
    assertStatus($db, [201], 'create database');
    $databaseId = $db['body']['$id'];

    $col = http('POST', $endpoint . '/databases/' . $databaseId . '/collections', $apiHeaders, [
        'collectionId' => 'unique()',
        'name' => 'Bench Collection',
        'permissions' => ['read("any")', 'create("any")', 'update("any")', 'delete("any")'],
        'documentSecurity' => false,
    ]);
    assertStatus($col, [201], 'create collection');
    $collectionId = $col['body']['$id'];

    $attrKeys = [];
    $attrCreateStart = microtime(true);
    for ($i = 0; $i < $attributeCount; $i++) {
        $keyName = 'a' . $i;
        $attrKeys[] = $keyName;
        $res = http('POST', $endpoint . '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes/string', $apiHeaders, [
            'key' => $keyName,
            'size' => 64,
            'required' => false,
        ]);
        assertStatus($res, [202], 'create attribute ' . $keyName);
        if ($pid < 0 && $i % 5 === 0) {
            $appendSample($samplesFile, sampleDockerStats());
        }
    }

    $available = 0;
    $deadline = microtime(true) + 180;
    while ($available < $attributeCount && microtime(true) < $deadline) {
        $list = http('GET', $endpoint . '/databases/' . $databaseId . '/collections/' . $collectionId . '/attributes?limit=100', $apiHeaders);
        assertStatus($list, [200], 'list attributes');
        $available = 0;
        foreach ($list['body']['attributes'] ?? [] as $attr) {
            if (($attr['status'] ?? '') === 'available') {
                $available++;
            } elseif (($attr['status'] ?? '') === 'failed') {
                throw new RuntimeException('Attribute failed: ' . ($attr['key'] ?? '?'));
            }
        }
        if ($available < $attributeCount) {
            usleep(200_000);
        }
        if ($pid < 0) {
            $appendSample($samplesFile, sampleDockerStats());
        }
    }
    $attrTotalMs = (microtime(true) - $attrCreateStart) * 1000;
    if ($available < $attributeCount) {
        throw new RuntimeException("Only {$available}/{$attributeCount} attributes became available");
    }
    out(sprintf('Attributes ready in %.0f ms (%.1f ms/attr)', $attrTotalMs, $attrTotalMs / $attributeCount));

    // Phase 2: documents create + delete → databases/deletes
    out("Phase 2: create {$documentCount} documents then delete them…");
    $docCreateStart = microtime(true);
    $docIds = [];
    for ($i = 0; $i < $documentCount; $i++) {
        $data = [];
        foreach (array_slice($attrKeys, 0, min(5, count($attrKeys))) as $k) {
            $data[$k] = 'v' . $i;
        }
        $res = http('POST', $endpoint . '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents', $apiHeaders, [
            'documentId' => 'unique()',
            'data' => $data,
        ]);
        assertStatus($res, [201], 'create document');
        $docIds[] = $res['body']['$id'];
        if ($pid < 0 && $i % 25 === 0) {
            $appendSample($samplesFile, sampleDockerStats());
        }
    }
    $docCreateMs = (microtime(true) - $docCreateStart) * 1000;

    $docDeleteStart = microtime(true);
    foreach ($docIds as $i => $docId) {
        $res = http('DELETE', $endpoint . '/databases/' . $databaseId . '/collections/' . $collectionId . '/documents/' . $docId, $apiHeaders);
        assertStatus($res, [204], 'delete document');
        if ($pid < 0 && $i % 25 === 0) {
            $appendSample($samplesFile, sampleDockerStats());
        }
    }
    $docDeleteMs = (microtime(true) - $docDeleteStart) * 1000;
    out(sprintf('Documents create %.0f ms / delete %.0f ms', $docCreateMs, $docDeleteMs));

    // Phase 3: users create + delete → deletes (+ optional mails)
    out("Phase 3: create {$userCount} users then delete them…");
    $userCreateStart = microtime(true);
    $userIds = [];
    $userCreateLatencies = [];
    for ($i = 0; $i < $userCount; $i++) {
        $t = microtime(true);
        $res = http('POST', $endpoint . '/users', $apiHeaders, [
            'userId' => 'unique()',
            'email' => uniqueId('u') . '@appwrite.test',
            'password' => 'Password123!',
            'name' => 'Bench User ' . $i,
        ]);
        assertStatus($res, [201], 'create user');
        $userIds[] = $res['body']['$id'];
        $userCreateLatencies[] = (microtime(true) - $t) * 1000;
    }
    $userCreateMs = (microtime(true) - $userCreateStart) * 1000;

    $userDeleteStart = microtime(true);
    $userDeleteLatencies = [];
    foreach ($userIds as $userId) {
        $t = microtime(true);
        $res = http('DELETE', $endpoint . '/users/' . $userId, $apiHeaders);
        assertStatus($res, [204], 'delete user');
        $userDeleteLatencies[] = (microtime(true) - $t) * 1000;
        if ($pid < 0) {
            $appendSample($samplesFile, sampleDockerStats());
        }
    }
    $userDeleteMs = (microtime(true) - $userDeleteStart) * 1000;
    out(sprintf('Users create %.0f ms / delete %.0f ms', $userCreateMs, $userDeleteMs));

    // Cool-down sample for peak capture
    sleep(3);
    if ($pid < 0) {
        for ($i = 0; $i < 6; $i++) {
            $appendSample($samplesFile, sampleDockerStats());
            usleep(500_000);
        }
    }

    // Cleanup team (cascades project)
    http('DELETE', $endpoint . '/teams/' . $teamId, $consoleHeaders);

    $finalSnap = sampleDockerStats();
    $samples = $loadSamples();
    $memSeries = array_column($samples, 'workerMemBytes');
    $cpuSeries = array_column($samples, 'workerCpuPct');
    $peakMem = $memSeries === [] ? $finalSnap['workerMemBytes'] : max($memSeries);
    $avgMem = $memSeries === [] ? $finalSnap['workerMemBytes'] : (int) round(array_sum($memSeries) / count($memSeries));
    $peakCpu = $cpuSeries === [] ? 0.0 : max($cpuSeries);

    $result = [
        'label' => $label,
        'branch' => trim((string) shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null')) ?: 'unknown',
        'commit' => trim((string) shell_exec('git rev-parse --short HEAD 2>/dev/null')) ?: 'unknown',
        'endpoint' => $endpoint,
        'startedAt' => date('c', (int) $startedAt),
        'finishedAt' => date('c'),
        'durationSec' => round(microtime(true) - $startedAt, 2),
        'workload' => [
            'attributes' => $attributeCount,
            'documents' => $documentCount,
            'users' => $userCount,
            'idleSeconds' => $idleSeconds,
        ],
        'containers' => [
            'count' => $finalSnap['containerCount'],
            'idleAvgMemMiB' => bytesToMiB($idleMem),
            'avgMemMiB' => bytesToMiB($avgMem),
            'peakMemMiB' => bytesToMiB($peakMem),
            'peakCpuPct' => round($peakCpu, 2),
            'idleAvgMemBytes' => $idleMem,
            'avgMemBytes' => $avgMem,
            'peakMemBytes' => $peakMem,
            'breakdown' => $finalSnap['containers'],
        ],
        'performance' => [
            'provisionMs' => round($provisionMs, 1),
            'attributesTotalMs' => round($attrTotalMs, 1),
            'attributesPerMs' => round($attrTotalMs / $attributeCount, 2),
            'documentsCreateMs' => round($docCreateMs, 1),
            'documentsCreatePerSec' => round($documentCount / max($docCreateMs / 1000, 0.001), 2),
            'documentsDeleteMs' => round($docDeleteMs, 1),
            'documentsDeletePerSec' => round($documentCount / max($docDeleteMs / 1000, 0.001), 2),
            'usersCreateMs' => round($userCreateMs, 1),
            'usersCreatePerSec' => round($userCount / max($userCreateMs / 1000, 0.001), 2),
            'usersDeleteMs' => round($userDeleteMs, 1),
            'usersDeletePerSec' => round($userCount / max($userDeleteMs / 1000, 0.001), 2),
            'usersCreateP50Ms' => percentile($userCreateLatencies, 50),
            'usersCreateP95Ms' => percentile($userCreateLatencies, 95),
            'usersDeleteP50Ms' => percentile($userDeleteLatencies, 50),
            'usersDeleteP95Ms' => percentile($userDeleteLatencies, 95),
            'e2eWorkerPathMs' => round($attrTotalMs + $docCreateMs + $docDeleteMs + $userCreateMs + $userDeleteMs, 1),
        ],
        'efficiency' => [
            'memPerContainerMiB' => $finalSnap['containerCount'] > 0
                ? round(bytesToMiB($idleMem) / $finalSnap['containerCount'], 2)
                : 0,
            'peakMemPerContainerMiB' => $finalSnap['containerCount'] > 0
                ? round(bytesToMiB($peakMem) / $finalSnap['containerCount'], 2)
                : 0,
            'idleMemVsPeakRatio' => $peakMem > 0 ? round($idleMem / $peakMem, 3) : 0,
        ],
        'sampleCount' => count($samples),
    ];

    $dir = dirname($output);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create output dir: ' . $dir);
    }
    file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
    out('Wrote ' . $output);
    out(sprintf(
        'Summary: containers=%d idle=%.1fMiB peak=%.1fMiB e2e=%.0fms attrs=%.0fms docs=%.0fms users=%.0fms',
        $result['containers']['count'],
        $result['containers']['idleAvgMemMiB'],
        $result['containers']['peakMemMiB'],
        $result['performance']['e2eWorkerPathMs'],
        $result['performance']['attributesTotalMs'],
        $result['performance']['documentsCreateMs'] + $result['performance']['documentsDeleteMs'],
        $result['performance']['usersCreateMs'] + $result['performance']['usersDeleteMs'],
    ));

    echo json_encode(['ok' => true, 'output' => $output], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    out('ERROR: ' . $e->getMessage());
    fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    exit(1);
} finally {
    if ($pid > 0) {
        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);
    }
    @unlink($samplesFile);
}
