<?php

/*
 * The `orchestration-php` server: runs the PHP side of the orchestration
 * operations that reach Docker, in a container that has the Docker socket at
 * /var/run/docker.sock, where utopia-php/orchestration expects it (the PHP
 * driver's container has none). Started by tests/compat/orchestration/services.sh:
 *
 *   POST /  {"op": "...", "args": {...}}  ->  {"ok": value} or {"err": {"class", "message"}} or {"fault": "..."}
 *   GET  /ping                            ->  true
 */

require __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../php/Fault.php';
require_once __DIR__ . '/../../php/Codec.php';
require_once __DIR__ . '/../Operations.php';

use Tests\Compat\Codec;
use Tests\Compat\Fault;
use Tests\Compat\Orchestration\Operations;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo 'true';

    return true;
}

try {
    $request = json_decode((string) file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
    $args = Codec::decode($request->args ?? new \stdClass());
    if ($args instanceof \stdClass) {
        $args = [];
    }
    $reply = ['ok' => Codec::encode(Operations::run((string) $request->op, $args))];
} catch (Fault $fault) {
    $reply = ['fault' => $fault->getMessage()];
} catch (\Throwable $error) {
    $reply = ['err' => ['class' => $error::class, 'message' => Codec::encode($error->getMessage())]];
}

echo json_encode($reply, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

return true;
