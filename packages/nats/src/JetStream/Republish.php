<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class Republish
{
    public function __construct(
        public readonly string $source,
        public readonly string $destination,
        public readonly bool $headersOnly = false,
    ) {}

    public function toArray(): array
    {
        $data = [
            'src' => $this->source,
            'dest' => $this->destination,
        ];
        if ($this->headersOnly) {
            $data['headers_only'] = true;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['src'] ?? '',
            destination: $data['dest'] ?? '',
            headersOnly: $data['headers_only'] ?? false,
        );
    }
}
