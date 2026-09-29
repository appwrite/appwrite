<?php

declare(strict_types=1);

$path = getenv('UTOPIA_ABUSE_FIXTURE') ?: throw new RuntimeException('Missing fixture state');
$state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
assert(is_array($state));
/** @var array{databases: array<string, array<string, bool>>, deletes: list<string>, createFailure: int, setupFailure: int, cleanupFailure: int} $state */
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$route = explode('/', trim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/'));
$body = json_decode((string) file_get_contents('php://input'), true) ?? [];
/** @var array{databaseId?: string, tableId?: string} $body */
$database = (string) ($route[2] ?? $body['databaseId'] ?? '');
$table = (string) ($route[4] ?? $body['tableId'] ?? '');
$status = 200;
$response = [];

if ($method === 'POST' && count($route) === 2) {
    $status = (int) $state['createFailure'];
    if ($status === 0 && isset($state['databases'][$database])) {
        $status = 409;
    }
    if ($status === 0) {
        $state['databases'][$database] = [];
        $status = 201;
        $response = [
            '$id' => $database, '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            '$updatedAt' => '2026-01-01T00:00:00.000+00:00',
            'name' => 'Utopia', 'enabled' => true, 'type' => 'tablesdb',
        ];
    }
} elseif ($method === 'DELETE' && count($route) === 3) {
    $state['deletes'][] = $database;
    $status = (int) $state['cleanupFailure'];
    if ($status === 0) {
        $status = isset($state['databases'][$database]) ? 204 : 404;
        unset($state['databases'][$database]);
    }
} elseif ($method === 'POST' && count($route) === 4) {
    $status = (int) $state['setupFailure'];
    if ($status === 0) {
        $state['databases'][$database][$table] = true;
        $status = 201;
        $response = [
            '$id' => $table, '$createdAt' => '2026-01-01T00:00:00.000+00:00',
            '$updatedAt' => '2026-01-01T00:00:00.000+00:00', '$permissions' => [],
            'databaseId' => $database, 'name' => $table, 'enabled' => true,
            'rowSecurity' => false, 'columns' => [], 'indexes' => [],
            'bytesMax' => 0, 'bytesUsed' => 0,
        ];
    }
} else {
    $status = 404;
}

if ($status >= 400) {
    $response = [
        'message' => $method === 'DELETE' ? 'cleanup rejected' : 'setup rejected',
        'code' => $status,
        'type' => $status === 409 ? 'database_already_exists' : 'fixture_rejected',
    ];
}

file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
http_response_code($status);
header($status === 204 ? 'Content-Type: text/plain' : 'Content-Type: application/json');
if ($status !== 204) {
    echo json_encode($response, JSON_THROW_ON_ERROR);
}
