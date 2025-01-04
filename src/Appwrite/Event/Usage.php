<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Usage extends Event
{
    protected array $metrics = [];
    protected array $reduce  = [];

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::USAGE_QUEUE_NAME)
            ->setClass(Event::USAGE_CLASS_NAME);
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
     * Add group.
     *
     * @param string $key
     * @return self
     */
    public function addGroup(string $key): self
    {

        $this->metrics[] = [
            'group' => $key,
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
        if ($this->paused) {
            return false;
        }

        $client = new Client($this->queue, $this->connection);

        $result = $client->enqueue([
            'project' => $this->getProject(),
            'reduce'  => $this->reduce,
            'metrics' => $this->metrics,
        ]);

        $this->metrics = [];

        return $result;
    }
}
