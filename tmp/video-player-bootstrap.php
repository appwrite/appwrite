<?php

/**
 * One-off: create a public-readable video + HLS rendition + client JWT for the player.
 * Run inside the appwrite container:
 *   php /tmp/video-player-bootstrap.php
 */

require '/usr/src/code/vendor/autoload.php';

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\System\System;

$client = new Client();
$client->setEndpoint('http://appwrite/v1');

function call(Client $client, string $method, string $path, array $headers = [], array $params = []): array
{
    $response = $client->call($method, $path, $headers, $params);
    $code = $response['headers']['status-code'] ?? 0;
    if ($code < 200 || $code >= 300) {
        fwrite(STDERR, "FAIL {$method} {$path} => {$code}\n");
        fwrite(STDERR, json_encode($response['body'] ?? [], JSON_PRETTY_PRINT) . "\n");
        exit(1);
    }
    return $response;
}

function wait(callable $fn, int $timeout = 300, int $intervalMs = 1000): mixed
{
    $deadline = time() + $timeout;
    while (time() < $deadline) {
        $last = $fn();
        if ($last !== null) {
            return $last;
        }
        usleep($intervalMs * 1000);
    }
    fwrite(STDERR, "Timed out waiting for async work\n");
    exit(1);
}

echo "==> Creating console account + project\n";

$email = 'video-demo-' . bin2hex(random_bytes(4)) . '@localhost.test';
$password = 'password';

call($client, Client::METHOD_POST, '/account', [
    'origin' => 'http://localhost',
    'content-type' => 'application/json',
    'x-appwrite-project' => 'console',
], [
    'userId' => ID::unique(),
    'email' => $email,
    'password' => $password,
    'name' => 'Video Demo',
]);

$session = call($client, Client::METHOD_POST, '/account/sessions/email', [
    'origin' => 'http://localhost',
    'content-type' => 'application/json',
    'x-appwrite-project' => 'console',
], [
    'email' => $email,
    'password' => $password,
]);

$consoleHeaders = [
    'origin' => 'http://localhost',
    'content-type' => 'application/json',
    'cookie' => 'a_session_console=' . $session['cookies']['a_session_console'],
    'x-appwrite-project' => 'console',
];

$team = call($client, Client::METHOD_POST, '/teams', $consoleHeaders, [
    'teamId' => ID::unique(),
    'name' => 'Video Demo Team',
]);

$project = call($client, Client::METHOD_POST, '/projects', $consoleHeaders, [
    'projectId' => ID::unique(),
    'region' => System::getEnv('_APP_REGION', 'default'),
    'name' => 'Video Player Demo',
    'teamId' => $team['body']['$id'],
    'description' => 'Local HLS player demo',
    'url' => 'http://localhost',
]);

$projectId = $project['body']['$id'];

$key = call($client, Client::METHOD_POST, '/projects/' . $projectId . '/keys', $consoleHeaders, [
    'keyId' => ID::unique(),
    'name' => 'Video Demo Key',
    'scopes' => [
        'users.read', 'users.write',
        'files.read', 'files.write',
        'buckets.read', 'buckets.write',
        'videos.read', 'videos.write',
        'platforms.read', 'platforms.write',
    ],
]);

$apiKey = $key['body']['secret'];

foreach (['localhost', '127.0.0.1'] as $hostname) {
    call($client, Client::METHOD_POST, '/projects/' . $projectId . '/platforms', $consoleHeaders, [
        'platformId' => ID::unique(),
        'type' => 'web',
        'name' => 'Player ' . $hostname,
        'hostname' => $hostname,
    ]);
}

$apiHeaders = [
    'content-type' => 'application/json',
    'x-appwrite-project' => $projectId,
    'x-appwrite-key' => $apiKey,
];

echo "==> Creating public bucket + uploading source MP4\n";

$bucket = call($client, Client::METHOD_POST, '/storage/buckets', $apiHeaders, [
    'bucketId' => 'unique()',
    'name' => 'Video sources',
    'fileSecurity' => false,
    'permissions' => [
        Permission::read(Role::any()),
        Permission::create(Role::any()),
        Permission::update(Role::any()),
        Permission::delete(Role::any()),
    ],
]);

$bucketId = $bucket['body']['$id'];
$source = '/usr/src/code/tests/resources/disk-a/large-file.mp4';
$chunkSize = 5 * 1024 * 1024;
$size = filesize($source);
$mimeType = mime_content_type($source);
$handle = fopen($source, 'rb');
$fileId = '';
$counter = 0;

while (!feof($handle)) {
    $data = fread($handle, $chunkSize);
    $curlFile = new CURLFile('data://' . $mimeType . ';base64,' . base64_encode($data), $mimeType, 'large-file.mp4');
    $headers = [
        'content-type' => 'multipart/form-data',
        'x-appwrite-project' => $projectId,
        'x-appwrite-key' => $apiKey,
        'content-range' => 'bytes ' . ($counter * $chunkSize) . '-' . min((($counter * $chunkSize) + $chunkSize) - 1, $size - 1) . '/' . $size,
    ];
    if ($fileId !== '') {
        $headers['x-appwrite-id'] = $fileId;
    }
    $response = $client->call(Client::METHOD_POST, '/storage/buckets/' . $bucketId . '/files', $headers, [
        'fileId' => $counter === 0 ? 'unique()' : $fileId,
        'file' => $curlFile,
    ]);
    $code = $response['headers']['status-code'] ?? 0;
    if ($code < 200 || $code >= 300) {
        fwrite(STDERR, "Upload chunk failed: {$code}\n");
        exit(1);
    }
    $fileId = $response['body']['$id'];
    $counter++;
}
fclose($handle);

echo "==> Creating video document\n";
$video = call($client, Client::METHOD_POST, '/videos', $apiHeaders, [
    'bucketId' => $bucketId,
    'fileId' => $fileId,
]);
$videoId = $video['body']['$id'];

echo "==> Waiting for timeline\n";
wait(function () use ($client, $apiHeaders, $videoId) {
    $response = $client->call(Client::METHOD_GET, '/videos/' . $videoId . '/timeline', $apiHeaders);
    if (($response['headers']['status-code'] ?? 0) === 200 && is_string($response['body']) && str_contains($response['body'], 'WEBVTT')) {
        return true;
    }
    return null;
});

echo "==> Creating HLS 360p rendition\n";
$profiles = call($client, Client::METHOD_GET, '/videos/profiles', $apiHeaders);
$profileId = null;
foreach ($profiles['body']['profiles'] as $profile) {
    if (($profile['name'] ?? '') === '360p') {
        $profileId = $profile['$id'];
        break;
    }
}
if ($profileId === null) {
    fwrite(STDERR, "Seeded 360p profile missing\n");
    exit(1);
}

$rendition = call($client, Client::METHOD_POST, '/videos/' . $videoId . '/renditions', $apiHeaders, [
    'profileId' => $profileId,
    'output' => 'hls',
]);
$renditionId = $rendition['body']['$id'];

echo "==> Waiting for encode to finish\n";
$ready = wait(function () use ($client, $apiHeaders, $videoId, $renditionId) {
    $response = $client->call(
        Client::METHOD_GET,
        '/videos/' . $videoId . '/renditions/' . $renditionId,
        $apiHeaders
    );
    $status = $response['body']['status'] ?? '';
    if (in_array($status, ['ready', 'error'], true)) {
        return $response['body'];
    }
    echo "  status={$status} progress=" . ($response['body']['progress'] ?? '?') . "\n";
    return null;
});

if (($ready['status'] ?? '') !== 'ready') {
    fwrite(STDERR, "Encode failed\n");
    exit(1);
}

// Guests do not have videos.read — create a client user + JWT for the browser player.
echo "==> Creating client session JWT\n";
$userEmail = 'viewer-' . bin2hex(random_bytes(3)) . '@localhost.test';
$userPassword = 'password';

call($client, Client::METHOD_POST, '/account', [
    'origin' => 'http://localhost',
    'content-type' => 'application/json',
    'x-appwrite-project' => $projectId,
], [
    'userId' => ID::unique(),
    'email' => $userEmail,
    'password' => $userPassword,
    'name' => 'Viewer',
]);

$userSession = call($client, Client::METHOD_POST, '/account/sessions/email', [
    'origin' => 'http://localhost',
    'content-type' => 'application/json',
    'x-appwrite-project' => $projectId,
], [
    'email' => $userEmail,
    'password' => $userPassword,
]);

$sessionCookie = null;
foreach ($userSession['cookies'] ?? [] as $name => $value) {
    if (str_starts_with($name, 'a_session_')) {
        $sessionCookie = $name . '=' . $value;
        break;
    }
}
if ($sessionCookie === null) {
    fwrite(STDERR, "No project session cookie returned\n");
    exit(1);
}

$jwtResponse = call($client, Client::METHOD_POST, '/account/jwts', [
    'origin' => 'http://localhost',
    'content-type' => 'application/json',
    'x-appwrite-project' => $projectId,
    'cookie' => $sessionCookie,
]);

$jwt = $jwtResponse['body']['jwt'] ?? '';
if ($jwt === '') {
    fwrite(STDERR, "JWT missing from response\n");
    exit(1);
}

$hlsUrl = 'http://localhost/v1/videos/' . $videoId . '/outputs/hls/master.m3u8?project=' . urlencode($projectId);

$out = [
    'projectId' => $projectId,
    'videoId' => $videoId,
    'renditionId' => $renditionId,
    'hlsUrl' => $hlsUrl,
    'jwt' => $jwt,
];

file_put_contents('/tmp/video-player-demo.json', json_encode($out, JSON_PRETTY_PRINT) . "\n");

echo "==> Ready\n";
echo "projectId={$projectId}\n";
echo "videoId={$videoId}\n";
echo "renditionId={$renditionId}\n";
echo "hlsUrl={$hlsUrl}\n";
echo "jwtLength=" . strlen($jwt) . "\n";
