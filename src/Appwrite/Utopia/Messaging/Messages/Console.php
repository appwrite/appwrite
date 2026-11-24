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
        protected ?string $teamId = null,
        protected string $alertId = '',
        protected string $alertStatus = '',
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

    /**
     * The organization the notification is about.
     */
    public function getTeamId(): ?string
    {
        return $this->teamId;
    }

    /**
     * The monitor alert the notification is about, or empty for any other notification.
     */
    public function getAlertId(): string
    {
        return $this->alertId;
    }

    /**
     * Whether the alert was firing or resolved when the notification was sent.
     */
    public function getAlertStatus(): string
    {
        return $this->alertStatus;
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
