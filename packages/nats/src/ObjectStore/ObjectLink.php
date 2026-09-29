<?php

declare(strict_types=1);

namespace Utopia\NATS\ObjectStore;

final class ObjectLink
{
    /**
     * @param string      $bucket target bucket
     * @param string|null $name   target object name (null for a bucket link)
     */
    public function __construct(
        public readonly string $bucket,
        public readonly ?string $name = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $data = ['bucket' => $this->bucket];
        if ($this->name !== null && $this->name !== '') {
            $data['name'] = $this->name;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            bucket: (string) ($data['bucket'] ?? ''),
            name: isset($data['name']) ? (string) $data['name'] : null,
        );
    }
}
