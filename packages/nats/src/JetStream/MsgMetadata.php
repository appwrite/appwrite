<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class MsgMetadata
{
    public function __construct(
        public readonly string $stream,
        public readonly string $consumer,
        public readonly int $numDelivered,
        public readonly int $streamSequence,
        public readonly int $consumerSequence,
        public readonly string $timestamp,
        public readonly int $numPending,
        public readonly ?string $domain = null,
    ) {}

    /**
     * Parse from reply subject: $JS.ACK.<stream>.<consumer>.<delivered>.<streamSeq>.<consumerSeq>.<timestamp>.<pending>
     * Or with domain: $JS.ACK.<domain>.<hash>.<stream>.<consumer>.<delivered>.<streamSeq>.<consumerSeq>.<timestamp>.<pending>
     */
    public static function fromReplySubject(string $reply): self
    {
        $parts = explode('.', $reply);

        // Standard format: $JS.ACK.<stream>.<consumer>.<delivered>.<streamSeq>.<consumerSeq>.<timestamp>.<pending>
        if (\count($parts) >= 9 && $parts[0] === '$JS' && $parts[1] === 'ACK') {
            if (\count($parts) === 9) {
                return new self(
                    stream: $parts[2],
                    consumer: $parts[3],
                    numDelivered: (int) $parts[4],
                    streamSequence: (int) $parts[5],
                    consumerSequence: (int) $parts[6],
                    timestamp: $parts[7],
                    numPending: (int) $parts[8],
                );
            }

            // Domain format has extra parts
            if (\count($parts) >= 11) {
                return new self(
                    stream: $parts[4],
                    consumer: $parts[5],
                    numDelivered: (int) $parts[6],
                    streamSequence: (int) $parts[7],
                    consumerSequence: (int) $parts[8],
                    timestamp: $parts[9],
                    numPending: (int) $parts[10],
                    domain: $parts[2],
                );
            }
        }

        throw new \InvalidArgumentException("Cannot parse JetStream reply subject: {$reply}");
    }
}
