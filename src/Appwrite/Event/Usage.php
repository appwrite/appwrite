<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Usage extends Event
{
    protected array $metrics = [];

    public function __construct(protected Connection $connection)
    {
        parent::__construct(Event::USAGE_QUEUE_NAME, Event::USAGE_CLASS_NAME);
    }

    /**
     * Add metric.
     *
     * @param string $key
     * @param int $value
     * @return self
     */
    public function addMetric(string $key, int $value): self
    {
        $this->metrics[] = [
            'key' => $key,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Sends metrics to the usage worker.
     *
     * @return string|bool
     */
    public function trigger(): string|bool
    {
        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'project' => $this->getProject(),
            'metrics' => $this->metrics,
        ]);
    }
}
