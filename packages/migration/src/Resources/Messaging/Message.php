<?php

namespace Utopia\Migration\Resources\Messaging;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Message extends Resource
{
    /**
     * @param string $id
     * @param string $providerType
     * @param array<string> $topics
     * @param array<string> $users
     * @param array<string> $targets
     * @param array<string, mixed> $data
     * @param string $messageStatus
     * @param string $scheduledAt
     * @param string $deliveredAt
     * @param array<string> $deliveryErrors
     * @param int $deliveredTotal
     */
    public function __construct(
        string $id,
        private readonly string $providerType,
        private readonly array $topics = [],
        private readonly array $users = [],
        private readonly array $targets = [],
        private readonly array $data = [],
        private readonly string $messageStatus = '',
        private readonly string $scheduledAt = '',
        private readonly string $deliveredAt = '',
        private readonly array $deliveryErrors = [],
        private readonly int $deliveredTotal = 0,
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
            $array['providerType'] ?? '',
            $array['topics'] ?? [],
            $array['users'] ?? [],
            $array['targets'] ?? [],
            $array['data'] ?? [],
            $array['messageStatus'] ?? $array['status'] ?? '',
            $array['scheduledAt'] ?? '',
            $array['deliveredAt'] ?? '',
            $array['deliveryErrors'] ?? [],
            $array['deliveredTotal'] ?? 0,
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
            'providerType' => $this->providerType,
            'topics' => $this->topics,
            'users' => $this->users,
            'targets' => $this->targets,
            'data' => $this->data,
            'messageStatus' => $this->messageStatus,
            'scheduledAt' => $this->scheduledAt,
            'deliveredAt' => $this->deliveredAt,
            'deliveryErrors' => $this->deliveryErrors,
            'deliveredTotal' => $this->deliveredTotal,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_MESSAGE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_MESSAGING;
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    /**
     * @return array<string>
     */
    public function getTopics(): array
    {
        return $this->topics;
    }

    /**
     * @return array<string>
     */
    public function getUsers(): array
    {
        return $this->users;
    }

    /**
     * @return array<string>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getMessageStatus(): string
    {
        return $this->messageStatus;
    }

    public function getScheduledAt(): string
    {
        return $this->scheduledAt;
    }

    public function getDeliveredAt(): string
    {
        return $this->deliveredAt;
    }

    /**
     * @return array<string>
     */
    public function getDeliveryErrors(): array
    {
        return $this->deliveryErrors;
    }

    public function getDeliveredTotal(): int
    {
        return $this->deliveredTotal;
    }
}
