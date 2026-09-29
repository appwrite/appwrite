<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class SubjectTransform
{
    public function __construct(
        public readonly string $source,
        public readonly string $destination,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'src' => $this->source,
            'dest' => $this->destination,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['src'] ?? '',
            destination: $data['dest'] ?? '',
        );
    }
}
