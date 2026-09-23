<?php

namespace Utopia\Messaging\Messages;

use Utopia\Messaging\Message;

class Discord implements Message
{
    private ?string $origin = null;

    /**
     * @param  array<string, mixed>|null  $embeds
     * @param  array<string, mixed>|null  $allowedMentions
     * @param  array<string, mixed>|null  $components
     * @param  array<string, mixed>|null  $attachments
     */
    public function __construct(
        private readonly string $content,
        private readonly ?string $username = null,
        private readonly ?string $avatarUrl = null,
        private readonly ?bool $tts = null,
        private readonly ?array $embeds = null,
        private readonly ?array $allowedMentions = null,
        private readonly ?array $components = null,
        private readonly ?array $attachments = null,
        private readonly ?string $flags = null,
        private readonly ?string $threadName = null,
        private readonly ?bool $wait = null,
        private readonly ?string $threadId = null,
    ) {}

    public function getContent(): string
    {
        return $this->content;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getTts(): ?bool
    {
        return $this->tts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEmbeds(): ?array
    {
        return $this->embeds;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAllowedMentions(): ?array
    {
        return $this->allowedMentions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getComponents(): ?array
    {
        return $this->components;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAttachments(): ?array
    {
        return $this->attachments;
    }

    public function getFlags(): ?string
    {
        return $this->flags;
    }

    public function getThreadName(): ?string
    {
        return $this->threadName;
    }

    public function getWait(): ?bool
    {
        return $this->wait;
    }

    public function getThreadId(): ?string
    {
        return $this->threadId;
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
