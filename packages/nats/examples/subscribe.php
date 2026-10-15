<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\NATS\Connection;
use Utopia\NATS\Message;

$conn = Connection::connect('nats://127.0.0.1:4222');

echo "Connected to NATS\n";

// Subscribe with callback
$conn->subscribe('greet.*', function (Message $msg) {
    echo "Received on {$msg->subject}: {$msg->data}\n";

    if ($msg->headers !== null) {
        foreach ($msg->headers->all() as $name => $values) {
            echo "  Header {$name}: " . implode(', ', $values) . "\n";
        }
    }
});

echo "Subscribed to greet.*, waiting for messages... (Ctrl+C to quit)\n";

// Process messages forever
$conn->wait();
