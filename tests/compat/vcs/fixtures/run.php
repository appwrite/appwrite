<?php

/*
 * The `vcs-http` fixture server the vcs cases call: an emulator of GitHub,
 * GitLab, Gitea, Forgejo, Gogs, Bitbucket and Origin, answering in each
 * provider's own shapes from bare git repositories it keeps per case
 * namespace, recording every API request it receives, and serving the
 * repositories over Git smart HTTP. Started by tests/compat/vcs/services.sh
 * in a throwaway container of the dev image:
 *
 *   php server.php <http port> <https port> <certificate dir> [recorder]
 *
 * With `recorder`, it also listens on the ports packages/vcs/tests/Services.php
 * names (Gitea 13000, Forgejo 13001, Gogs 13002, GitLab 13003, the catcher
 * 15000 and 5000), for tests/compat/vcs/recorder/record.php.
 */

namespace Tests\Compat\Vcs\Fixtures;

use Swoole\Http\Server as SwooleServer;

require_once __DIR__ . '/Git.php';
require_once __DIR__ . '/World.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Server.php';
require_once __DIR__ . '/GitHub.php';
require_once __DIR__ . '/GitLab.php';
require_once __DIR__ . '/Gitea.php';
require_once __DIR__ . '/Bitbucket.php';
require_once __DIR__ . '/Origin.php';

$httpPort = (int) ($argv[1] ?? 8080);
$httpsPort = (int) ($argv[2] ?? 8443);
$certs = $argv[3] ?? '/tmp/compat-fs/vcs-certs';
$recorder = ($argv[4] ?? '') === 'recorder';

$ports = $recorder ? [13000 => 'gitea', 13001 => 'forgejo', 13002 => 'gogs', 13003 => 'gitlab', 15000 => 'catcher', 5000 => 'catcher'] : [];
$root = sys_get_temp_dir() . '/vcs-fixtures';
@mkdir($root, 0777, true);
$emulator = new Server($root, $ports);

$http = new SwooleServer('0.0.0.0', $httpPort, SWOOLE_BASE);
$http->set([
    'worker_num' => 1,
    'enable_coroutine' => false,
    'http_compression' => false,
    'package_max_length' => 64 * 1024 * 1024,
]);
$https = $http->addListener('0.0.0.0', $httpsPort, SWOOLE_SOCK_TCP | SWOOLE_SSL);
if ($https !== false) {
    $https->set([
        'ssl_cert_file' => $certs . '/server.pem',
        'ssl_key_file' => $certs . '/server.key',
        'open_http_protocol' => true,
    ]);
}
foreach (array_keys($ports) as $port) {
    $listener = $http->addListener('0.0.0.0', $port, SWOOLE_SOCK_TCP);
    if ($listener !== false) {
        $listener->set(['open_http_protocol' => true]);
    }
}
$http->on('request', $emulator->serve(...));
$http->start();
