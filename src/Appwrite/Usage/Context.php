<?php

namespace Appwrite\Usage;

use Utopia\Database\Document;

class Context
{
    protected array $metrics = [];
    protected array $reduce = [];
    protected array $disabled = [];

    /**
     * Add a metric
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
     * Add a document to reduce
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
     * Disable a metric
     *
     * @param string $key
     * @return self
     */
    public function disableMetric(string $key): self
    {
        $this->disabled[] = $key;
        return $this;
    }

    /**
     * Get all metrics (filtered by disabled)
     *
     * @return array<array{key: string, value: int}>
     */
    public function getMetrics(): array
    {
        return array_filter($this->metrics, function ($metric) {
            foreach ($this->disabled as $disabledMetric) {
                if (str_ends_with($metric['key'], $disabledMetric)) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Get all reduce documents
     *
     * @return array<Document>
     */
    public function getReduce(): array
    {
        return $this->reduce;
    }

    /**
     * Check if context is empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->metrics) && empty($this->reduce);
    }

    /**
     * Reset the context
     *
     * @return self
     */
    public function reset(): self
    {
        $this->metrics = [];
        $this->reduce = [];
        $this->disabled = [];
        return $this;
    }
}
