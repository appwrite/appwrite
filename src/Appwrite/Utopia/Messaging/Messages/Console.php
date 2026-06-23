<?php

namespace Appwrite\Utopia\Messaging\Messages;

use Utopia\Messaging\Message;

class Console implements Message
{
    private ?string $origin = null;

    /**
     * @param array<int, array{address?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string, alertId?: string, recipientHash?: string}> $recipients
     */
    public function __construct(
        protected array $recipients,
        protected string $title,
        protected string $body,
        protected string $type = 'info',
        protected ?string $messageId = null,
        protected ?string $projectId = null,
        protected ?string $projectInternalId = null,
    ) {
    }

    /**
     * @return array<int, array{address?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string, alertId?: string, recipientHash?: string}>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * @return array<int, array{address?: string, resourceType: string, resourceId: string, resourceInternalId: string, parentResourceType: string, parentResourceId: string, parentResourceInternalId: string, alertId?: string, recipientHash?: string}>
     */
    public function getTo(): array
    {
        return $this->recipients;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getProjectId(): ?string
    {
        return $this->projectId;
    }

    public function getProjectInternalId(): ?string
    {
        return $this->projectInternalId;
    }

    public function setOrigin(?string $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }
}
