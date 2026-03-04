<?php

namespace Appwrite\Usage;

use Utopia\Database\Document;

class Context
{
    protected array $metrics = [];

    protected array $reduce = [];

    /**
     * Add a metric
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
     */
    public function isEmpty(): bool
    {
        return empty($this->metrics) && empty($this->reduce);
    }

    /**
     * Reset the context
     */
    public function reset(): self
    {
        $this->metrics = [];
        $this->reduce = [];

        return $this;
    }
}
