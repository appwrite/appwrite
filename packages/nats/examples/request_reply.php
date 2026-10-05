<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\NATS\Connection;
use Utopia\NATS\Message;

$conn = Connection::connect('nats://127.0.0.1:4222');

echo "Connected to NATS\n";

// Set up a service that echoes messages
$conn->subscribe('echo', function (Message $msg) use ($conn) {
    echo "Service received: {$msg->data}\n";
    if ($msg->replyTo !== null) {
        $conn->publish($msg->replyTo, 'Echo: ' . $msg->data);
    }
});

// Send a request
$response = $conn->request('echo', 'Hello, NATS!', 2.0);
echo "Got response: {$response->data}\n";

$conn->close();
echo "Done\n";
