<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

/**
 * A durable consumer on the origin stream that a source or mirror reads through
 * (server 2.14+). It must exist, push to $deliverSubject and use {@see AckPolicy::FlowControl}.
 * It keeps the source's position, which an ephemeral consumer re-derives by scanning
 * the target stream, wrongly for WorkQueue or Interest retention.
 */
final class StreamConsumerSource
{
    public function __construct(
        public readonly string $name,
        public readonly string $deliverSubject,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'deliver_subject' => $this->deliverSubject,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: \is_string($data['name'] ?? null) ? $data['name'] : '',
            deliverSubject: \is_string($data['deliver_subject'] ?? null) ? $data['deliver_subject'] : '',
        );
    }
}
