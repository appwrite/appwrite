<?php

namespace Utopia\Abuse;

abstract class Adapter
{
    /**
     * @var array<string, string>
     */
    protected array $params = [];

    /**
     * @var string
     */
    protected string $key = '';

    /**
     * Check
     *
     * Checks if number of counts is bigger or smaller than current limit
     *
     * @return bool
     */
    abstract public function check(): bool;

    /**
    * Set Param
    *
    * Set custom param for key pattern parsing
    *
    * @param  string  $key
    * @param  string  $value
    * @return $this
    */
    public function setParam(string $key, string $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
    * Get Params
    *
    * Return array of all key params
    *
    * @return array<string, string>
    */
    protected function getParams(): array
    {
        return $this->params;
    }

    /**
    * Parse key with all custom attached params
    *
    * @return string
    */
    protected function parseKey(): string
    {
        foreach ($this->getParams() as $key => $value) {
            $this->key = \str_replace($key, $value, $this->key);
        }

        return $this->key;
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
    abstract public function getLogs(?int $offset = null, ?int $limit = 25): array;

    /**
     * Delete all logs older than $datetime
     *
     * @param  int  $timestamp
     * @return bool
     */
    abstract public function cleanup(int $timestamp): bool;

    /**
     * Reset
     *
     * Reset the count to 0
     *
     * @return void
     */
    abstract public function reset(): void;
}
