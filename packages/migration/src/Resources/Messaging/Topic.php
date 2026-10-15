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
     */
    public function __construct(
        string $id,
        private readonly string $name,
        private readonly array $subscribe = [],
        protected string $createdAt = '',
        protected string $updatedAt = '',
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
}
