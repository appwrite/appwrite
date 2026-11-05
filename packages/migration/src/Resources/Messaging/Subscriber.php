<?php

namespace Utopia\Migration\Resources\Messaging;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Subscriber extends Resource
{
    /**
     * @param string $id
     * @param string $topicId
     * @param string $targetId
     * @param string $userId
     * @param string $userName
     * @param string $providerType
     */
    public function __construct(
        string $id,
        private readonly string $topicId,
        private readonly string $targetId,
        private readonly string $userId = '',
        private readonly string $userName = '',
        private readonly string $providerType = '',
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
            $array['topicId'] ?? '',
            $array['targetId'] ?? '',
            $array['userId'] ?? '',
            $array['userName'] ?? '',
            $array['providerType'] ?? '',
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
            'topicId' => $this->topicId,
            'targetId' => $this->targetId,
            'userId' => $this->userId,
            'userName' => $this->userName,
            'providerType' => $this->providerType,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_SUBSCRIBER;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_MESSAGING;
    }

    public function getTopicId(): string
    {
        return $this->topicId;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }
}
