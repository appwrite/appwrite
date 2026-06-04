<?php

use Appwrite\Push\Broker;
use Appwrite\Push\Token;
use Utopia\CLI\Console;
use Utopia\System\System;

require_once __DIR__ . '/init.php';

$signingKey = System::getEnv('_APP_PUSH_SIGNING_KEY', '');
if ($signingKey === '') {
    Console::error('_APP_PUSH_SIGNING_KEY must be set to start the Appwrite Push broker.');
    exit(1);
}

$tls = \filter_var(System::getEnv('_APP_PUSH_TLS', 'enabled'), FILTER_VALIDATE_BOOL)
    || System::getEnv('_APP_PUSH_TLS', 'enabled') === 'enabled';

$port = (int)System::getEnv('_APP_PUSH_PORT', $tls ? 8883 : 1883);
$cert = System::getEnv('_APP_PUSH_TLS_CERT', '');
$key = System::getEnv('_APP_PUSH_TLS_KEY', '');
$keepAlive = (int)System::getEnv('_APP_PUSH_KEEPALIVE', 1800);
$retention = (int)System::getEnv('_APP_PUSH_RETENTION', 86400);

$broker = new Broker(
    tokens: new Token($signingKey),
    port: $port,
    tls: $tls,
    tlsCertificate: $cert,
    tlsKey: $key,
    maxKeepAlive: $keepAlive,
    retentionSeconds: $retention,
);

$broker->start();
