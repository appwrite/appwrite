<?php

namespace Utopia\Agents;

class Message
{
    protected string $role;

    protected string $content;

    /**
     * @var array<Message>
     */
    protected array $attachments;

    /**
     * Create a new message
     *
     * @param  array<int, mixed>  $attachments
     */
    public function __construct(
        string $content,
        ?string $role = null,
        array $attachments = []
    ) {
        $this->content = $content;
        $this->role = $role ?? Role::ROLE_USER;
        $this->attachments = [];
        $this->setAttachments($attachments);
    }

    /**
     * Get the message role
     */
    public function getRole(): string
    {
        return $this->role;
    }

    /**
     * Get the message content
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * Detect MIME type of message content.
     */
    public function getMimeType(): ?string
    {
        if (empty($this->content)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($this->content);

        return $mimeType === false ? null : $mimeType;
    }

    /**
     * Clone the message with a different role.
     */
    public function withRole(string $role): static
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    /**
     * Attach a message payload (for example an image) to this message.
     */
    public function addAttachment(Message $attachment): self
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    /**
     * @param  array<int, mixed>  $attachments
     */
    public function setAttachments(array $attachments): self
    {
        $this->attachments = [];
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Message) {
                throw new \InvalidArgumentException('Attachments must be Message instances');
            }
            $this->attachments[] = $attachment;
        }

        return $this;
    }

    /**
     * @return array<Message>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function hasAttachments(): bool
    {
        return ! empty($this->attachments);
    }
}
