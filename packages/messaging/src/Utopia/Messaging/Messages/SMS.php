<?php

namespace Utopia\Messaging\Messages;

use Utopia\Messaging\Message;

class SMS implements Message
{
    private ?string $origin = null;

    /**
     * @param  array<string>  $to
     * @param  array<string>|null  $attachments
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        private readonly array $to,
        private readonly string $content,
        private readonly ?string $from = null,
        private readonly ?array $attachments = null,
        private ?array $metadata = null,
    ) {}

    /**
     * @return array<string>
     */
    public function getTo(): array
    {
        return $this->to;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFrom(): ?string
    {
        return $this->from;
    }

    /**
     * @return array<string>|null
     */
    public function getAttachments(): ?array
    {
        return $this->attachments;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
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
