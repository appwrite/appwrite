<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

use Utopia\NATS\Headers;

final class StreamMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly int $sequence,
        public readonly string $data,
        public readonly ?string $time = null,
        public readonly ?Headers $headers = null,
    ) {}

    /**
     * Parse the `message` object returned by $JS.API.STREAM.MSG.GET.
     * `data` and `hdrs` are base64-encoded on the wire.
     */
    public static function fromArray(array $data): self
    {
        $payload = isset($data['data']) ? (base64_decode($data['data'], true) ?: '') : '';

        $headers = null;
        if (isset($data['hdrs'])) {
            $raw = base64_decode($data['hdrs'], true);
            if ($raw !== false && $raw !== '') {
                $headers = Headers::fromWire($raw);
            }
        }

        return new self(
            subject: $data['subject'] ?? '',
            sequence: $data['seq'] ?? 0,
            data: $payload,
            time: $data['time'] ?? null,
            headers: $headers,
        );
    }
}
