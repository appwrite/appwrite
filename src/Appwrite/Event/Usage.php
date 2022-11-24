<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Usage extends Event
{
    protected array $metrics = [];

    public function __construct(protected Connection $connection)
    {
        parent::__construct(Event::FUNCTIONS_QUEUE_NAME, Event::FUNCTIONS_CLASS_NAME);
    }

    /**
     * Sets function document for the function event.
     *
     * @param string $namespace
     * @param string $key
     * @param int $value
     * @return self
     */
    public function addMetric(string $namespace, string $key, int $value): self
    {
        $this->metrics[] = [
            'namespace' => $namespace,
            'key' => $key,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Executes the function event and sends it to the functions worker.
     *
     * @return bool
     */
    public function trigger(): string|bool
    {
        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'project' => $this->project,
            'user' => $this->user,
            'type' => $this->type,
            'payload' => $this->payload,
            'metrics' => $this->metrics,
        ]);
    }
}
