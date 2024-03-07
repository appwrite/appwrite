<?php

namespace Appwrite\Event;

use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Queue\Connection;

class Messaging extends Event
{
    protected string $type = '';
    protected ?string $messageId = null;
    protected ?Document $message = null;
    protected ?array $recipients = null;
    protected ?string $scheduledAt = null;
    protected ?string $providerType = null;

    public function __construct(protected Connection $connection)
    {
        parent::__construct($connection);

        $this
            ->setQueue(Event::MESSAGING_QUEUE_NAME)
            ->setClass(Event::MESSAGING_CLASS_NAME);
    }

    /**
     * Sets type for the build event.
     *
     * @param string $type Can be `MESSAGE_TYPE_INTERNAL` or `MESSAGE_TYPE_EXTERNAL`.
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns set type for the function event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Sets recipient for the messaging event.
     *
     * @param string[] $recipients
     * @return self
     */
    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;

        return $this;
    }

    /**
     * Returns set recipient for messaging event.
     *
     * @return string[]
     */
    public function getRecipient(): array
    {
        return $this->recipients;
    }

    /**
     * Sets message document for the messaging event.
     *
     * @param Document $message
     * @return self
     */
    public function setMessage(Document $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Returns message document for the messaging event.
     *
     * @return string
     */
    public function getMessage(): Document
    {
        return $this->message;
    }

    /**
     * Sets message ID for the messaging event.
     *
     * @param string $message
     * @return self
     */
    public function setMessageId(string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    /**
     * Returns set message ID for the messaging event.
     *
     * @return string
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * Sets provider type for the messaging event.
     *
     * @param string $providerType
     * @return self
     */
    public function setProviderType(string $providerType): self
    {
        $this->providerType = $providerType;

        return $this;
    }

    /**
     * Returns set provider type for the messaging event.
     *
     * @return string
     */
    public function getProviderType(): string
    {
        return $this->providerType;
    }

    /**
     * Sets Scheduled delivery time for the messaging event.
     *
     * @param string $scheduledAt
     * @return self
     */
    public function setScheduledAt(string $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    /**
     * Returns set Delivery Time for the messaging event.
     *
     * @return string
     */
    public function getScheduledAt(): string
    {
        return $this->scheduledAt;
    }

    /**
     * Set project for this event.
     *
     * @param Document $project
     * @return self
     */
    public function setProject(Document $project): self
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Executes the event and sends it to the messaging worker.
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string | bool
    {
        $client = new Client($this->queue, $this->connection);

        return $client->enqueue([
            'type' => $this->type,
            'project' => $this->project,
            'user' => $this->user,
            'messageId' => $this->messageId,
            'message' => $this->message,
            'recipients' => $this->recipients,
            'providerType' => $this->providerType,
        ]);
    }
}
