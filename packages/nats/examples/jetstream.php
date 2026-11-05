<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Utopia\NATS\Connection;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\StreamConfig;

$conn = Connection::connect('nats://127.0.0.1:4222');
$js = $conn->jetStream();

echo "Connected to NATS with JetStream\n";

// Create a stream
$stream = $js->createOrUpdateStream(new StreamConfig(
    name: 'ORDERS',
    subjects: ['orders.>'],
));
echo "Stream ORDERS created\n";

// Publish messages
for ($i = 1; $i <= 5; $i++) {
    $ack = $js->publish('orders.new', json_encode(['id' => $i, 'item' => "Item {$i}"]));
    echo "Published order {$i}, stream seq: {$ack->sequence}\n";
}

// Create a pull consumer
$consumer = $js->createConsumer('ORDERS', new ConsumerConfig(
    name: 'order-processor',
    durableName: 'order-processor',
    ackPolicy: AckPolicy::Explicit,
    filterSubject: 'orders.>',
));
echo "Consumer created: {$consumer->getName()}\n";

// Fetch messages
$batch = $consumer->fetch(5, 5.0);
echo "Fetched {$batch->count()} messages\n";

foreach ($batch as $msg) {
    $data = json_decode($msg->getData(), true);
    echo "Processing order: {$data['id']} - {$data['item']}\n";
    $msg->ack();
}

// Clean up
$stream->delete();
echo "Stream deleted\n";

$conn->close();
echo "Done\n";
