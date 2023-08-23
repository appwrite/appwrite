<?php

namespace Appwrite\Event;

use Utopia\Queue\Client;
use Utopia\Queue\Connection;
use Utopia\Database\Document;

class Usage extends Event
{
    protected array $metrics = [];
    protected array $reduce  = [];

    public function __construct(protected Connection $connection)
    {
        parent::__construct(Event::USAGE_QUEUE_NAME, Event::USAGE_CLASS_NAME);
    }

    /**
     * Add reduce.
     *
     * @param Document $document
     * @return self
     */
    public function addReduce(Document $document): self
    {
        $this->reduce[] = $document;

        return $this;
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
            'reduce'  => $this->reduce,
            'metrics' => $this->metrics,
        ]);
    }
}
