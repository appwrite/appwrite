<?php

require_once __DIR__ . '/../worker.php';

use Appwrite\Messaging\Adapter\Realtime;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\DateTime;
use Utopia\Queue\Message;

$server->job()
    ->inject('message')
    ->inject('cache')
    ->action(function (Message $message, Cache $cache) {
        $payload = $message->getPayload();
        $type = $payload['type'];
        $key  = $payload['key'];
        $time = DateTime::now();

        switch ($type) {
            case 'cache':
                $cache->setListenersStatus(false);
                Console::log("[{$time}] Purging cache key   {$key}");
                $cache->purge($key);
                $cache->setListenersStatus(true);
                break;
            case 'realtime':
                Console::log("[{$time}] Sending realtime message");
                Realtime::send(
                    projectId: $key['projectId'],
                    payload: $key['payload'],
                    events: $key['events'],
                    channels: $key['channels'],
                    roles: $key['roles'],
                    options: $key['options']
                );
                break;
            default:
                break;
        }
    });

$server
    ->workerStart()
    ->action(function () {
        Console::success("In  [" . App::getEnv('_APP_REGION', 'nyc1') . "] edge cache purging worker Started");
    });

$server->start();
