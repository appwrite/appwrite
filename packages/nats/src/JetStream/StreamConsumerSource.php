<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

/**
 * A durable consumer on the origin stream that a source or mirror reads through
 * (server 2.14+). The consumer must already exist, push to $deliverSubject and use
 * {@see AckPolicy::FlowControl}.
 *
 * Without one the server creates an ephemeral consumer and, on every leader change
 * or restart, works out where to resume by scanning its own stream for the last
 * sourced message. A stream that deletes what it has delivered (WorkQueue or
 * Interest retention) has nothing to scan, so the source restarts from a guess and
 * copies again what it already copied. A durable consumer keeps that position on the
 * origin stream instead.
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
