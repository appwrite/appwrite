<?php

require_once __DIR__ . '/../worker.php';

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Queue\Message;

$server->job()
    ->inject('message')
    ->inject('cache')
    ->action(function (Message $message, Cache $cache) {
        $time = DateTime::now();

        $cache->setDisableListeners(true);

        foreach ($message->getPayload()['keys'] ?? [] as $key) {
            Console::log("[{$time}] Purging  {$key}");
            $cache->purge($key);
        }

        $cache->setDisableListeners(false);
    });

$server
    ->workerStart()
    ->action(function () {
        Console::success("In  [" . App::getEnv('_APP_REGION', 'nyc1') . "] edge cache purging worker Started");
    });

$server->start();
