<?php

namespace Appwrite\Usage;

use Utopia\Database\Document;

class Context
{
    protected array $metrics = [];
    protected array $reduce = [];

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
     * Get all metrics
     *
     * @return array<array{key: string, value: int}>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
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
        return $this;
    }
}
