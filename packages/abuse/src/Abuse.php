<?php

namespace Utopia\Abuse;

class Abuse
{
    /**
     * @var Adapter
     */
    protected Adapter $adapter;

    /**
     * @param  Adapter  $adapter
     */
    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Check
     *
     * Checks if request is considered abuse or not
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->adapter->check();
    }

    /**
     * Get abuse logs
     *
     * Return logs with an offset and limit
     *
     * @param  int|null  $offset
     * @param  int|null  $limit
     * @return array<string, mixed>
     */
    public function getLogs(?int $offset = null, ?int $limit = 25): array
    {
        return $this->adapter->getLogs($offset, $limit);
    }

    /**
     * Delete all logs older than $timestamp
     *
     * @param  int  $timestamp
     * @return bool
     */
    public function cleanup(int $timestamp): bool
    {
        return $this->adapter->cleanup($timestamp);
    }

    /**
     * Reset
     *
     * Reset the count to 0 for the current adapter
     *
     * @return void
     */
    public function reset(): void
    {
        $this->adapter->reset();
    }
}
