<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class ConsumerLimits
{
    /**
     * @param float|null $inactiveThreshold Inactive threshold in seconds
     */
    public function __construct(
        public readonly ?float $inactiveThreshold = null,
        public readonly ?int $maxAckPending = null,
    ) {}

    public function toArray(): array
    {
        $data = [];
        if ($this->inactiveThreshold !== null) {
            $data['inactive_threshold'] = StreamConfig::secondsToNanos($this->inactiveThreshold);
        }
        if ($this->maxAckPending !== null) {
            $data['max_ack_pending'] = $this->maxAckPending;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            inactiveThreshold: isset($data['inactive_threshold'])
                ? StreamConfig::nanosToSeconds($data['inactive_threshold'])
                : null,
            maxAckPending: $data['max_ack_pending'] ?? null,
        );
    }
}
