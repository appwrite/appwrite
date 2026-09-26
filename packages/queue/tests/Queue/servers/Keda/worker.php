<?php

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../tests.php';

use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Broker\Redis as RedisBroker;
use Utopia\Queue\Connection\Redis as RedisConnection;
use Utopia\Queue\Message;
use Utopia\Queue\Server;

/**
 * Worker image for the KEDA ScaledJob POC. KEDA spawns this as a Kubernetes Job
 * when the Redis queue has pending messages; the KubernetesJob adapter drains
 * the queue with the ordinary Redis broker and exits so the Job completes. The
 * payload never touches Kubernetes — it stays in Redis and is pulled here.
 */
$connection = new RedisConnection(getenv('REDIS_HOST') ?: 'redis', 6379);
$broker = new RedisBroker($connection, $connection);
$adapter = new KubernetesJob($broker, 1, getenv('QUEUE_NAMESPACE') ?: 'utopia-queue');

$server = new Server($adapter);
$server->job(getenv('QUEUE_NAME') ?: 'keda')
    ->inject('message')
    ->action(fn(Message $message) => handleRequest($message));
$server->error()
    ->inject('error')
    ->action(fn(\Throwable $error): int|false => fwrite(STDERR, $error->getMessage() . "\n"));
$server->start();
