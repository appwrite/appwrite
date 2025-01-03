<?php

namespace Appwrite\Event;

use Kafka\Producer;

class EventDispatcher {
    private $producer;
    private $topics = [];

    public function __construct(Producer $producer) {
        $this->producer = $producer;
    }

    public function dispatch(string $eventName, array $payload) {
        $message = [
            'event' => $eventName,
            'payload' => $payload,
            'timestamp' => time()
        ];

        $this->producer->send([
            [
                'topic' => $this->getTopicForEvent($eventName),
                'value' => json_encode($message),
                'key' => $payload['userId'] ?? null
            ]
        ]);
    }

    private function getTopicForEvent(string $eventName): string {
        return $this->topics[$eventName] ?? 'default';
    }
}
