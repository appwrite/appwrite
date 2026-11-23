<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\NATS\Connection;

$conn = Connection::connect('nats://127.0.0.1:4222');

echo "Connected to NATS\n";

// Simple publish
$conn->publish('greet.world', 'Hello, World!');
echo "Published to greet.world\n";

// Publish with headers
$headers = new \Utopia\NATS\Headers();
$headers->set('Content-Type', 'application/json');
$conn->publish('events.user', '{"action":"login","user":"alice"}', headers: $headers);
echo "Published to events.user with headers\n";

$conn->flush();
$conn->close();

echo "Done\n";
