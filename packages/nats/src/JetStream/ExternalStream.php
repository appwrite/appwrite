<?php

declare(strict_types=1);

namespace Utopia\NATS\JetStream;

final class ExternalStream
{
    public function __construct(
        public readonly string $api,
        public readonly ?string $deliver = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = ['api' => $this->api];
        if ($this->deliver !== null) {
            $data['deliver'] = $this->deliver;
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            api: $data['api'] ?? '',
            deliver: $data['deliver'] ?? null,
        );
    }
}
