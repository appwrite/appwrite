<?php

namespace Appwrite\Event;

use Resque;

class SyncOut extends Event
{
    protected string $key = '';
    protected string $region = '';

    public function __construct()
    {
        parent::__construct(Event::SYNCS_OUT_QUEUE_NAME, Event::SYNCS_OUT_CLASS_NAME);
    }

    /**
     * Sets cache key.
     *
     * @param string $key
     * @return self
     */
    public function addKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Returns cache key.
     *
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Sets cloud region.
     *
     * @param string $region
     * @return self
     */
    public function setRegion(string $region): self
    {
        $this->host = $region;

        return $this;
    }

    /**
     * Returns cloud region.
     *
     * @return string
     */
    public function getRegion(): string
    {
        return $this->region;
    }

    /**
     * Executes the event and sends it to the messaging worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'key' => $this->key,
        ]);
    }
}
