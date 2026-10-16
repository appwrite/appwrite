<?php

namespace Utopia\Migration\Resources\Messaging;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Topic extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param array<string> $subscribe
     * @param int|null $qos MQTT delivery QoS for the topic; null lets the subscriber choose
     * @param int|null $expiry Offline delivery retention in seconds; null inherits the providers' expiry
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly array $subscribe = [],
        protected string $createdAt = '',
        protected string $updatedAt = '',
        private readonly ?int $qos = null,
        private readonly ?int $expiry = null,
    ) {
        $this->id = $id;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['name'] ?? '',
            $array['subscribe'] ?? [],
            $array['createdAt'] ?? '',
            $array['updatedAt'] ?? '',
            $array['qos'] ?? null,
            $array['expiry'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subscribe' => $this->subscribe,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'qos' => $this->qos,
            'expiry' => $this->expiry,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_TOPIC;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_MESSAGING;
    }

    public function getTopicName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getSubscribe(): array
    {
        return $this->subscribe;
    }

    public function getQos(): ?int
    {
        return $this->qos;
    }

    public function getExpiry(): ?int
    {
        return $this->expiry;
    }
}
