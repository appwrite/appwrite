<?php

declare(strict_types=1);

namespace Utopia\NATS\Services;

/**
 * Internal per-endpoint registration and running statistics.
 */
final class Endpoint
{
    public int $numRequests = 0;
    public int $numErrors = 0;
    /** Total processing time in nanoseconds. */
    public int $processingTime = 0;
    public ?string $lastError = null;

    /** @var callable(\Utopia\NATS\Message): string */
    public $handler;

    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $subject,
        callable $handler,
        public readonly string $queueGroup = 'q',
        public readonly array $metadata = [],
    ) {
        $this->handler = $handler;
    }

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return [
            'name' => $this->name,
            'subject' => $this->subject,
            'queue_group' => $this->queueGroup,
            'metadata' => $this->metadata === [] ? new \stdClass() : $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $average = $this->numRequests > 0
            ? intdiv($this->processingTime, $this->numRequests)
            : 0;

        return [
            'name' => $this->name,
            'subject' => $this->subject,
            'queue_group' => $this->queueGroup,
            'metadata' => $this->metadata === [] ? new \stdClass() : $this->metadata,
            'num_requests' => $this->numRequests,
            'num_errors' => $this->numErrors,
            'last_error' => $this->lastError ?? '',
            'processing_time' => $this->processingTime,
            'average_processing_time' => $average,
        ];
    }
}
