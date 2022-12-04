<?php

require_once __DIR__ . '/../worker.php';

use Swoole\Timer;
use Utopia\Queue\Message;

$stack = [];

$server->job()
    ->inject('message')
    ->action(function (Message $message) use (&$stack) {
        $payload = $message->getPayload() ?? [];
        foreach ($payload['metrics'] ?? [] as $metric) {
            if (!isset($stack[$metric['namespace']][$metric['key']])) {
                $stack[$metric['namespace']][$metric['key']] = $metric['value'];
                continue;
            }
            $stack[$metric['namespace']][$metric['key']] += $metric['value'];
        }
    });

$server
    ->workerStart()
    ->action(function () use (&$stack) {
        Timer::tick(30000, function () use (&$stack) {
            $chunk = array_slice($stack, 0, count($stack));
            array_splice($stack, 0, count($stack));
            var_dump($chunk);

        });
    });

$server->start();
