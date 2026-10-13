<?php

declare(strict_types=1);

namespace Tests\Compat\Agents;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;

/**
 * The fake adapters of the library's tests (DiffCheckTest's FakeAdapter,
 * ConversationTest's ConversationFakeAdapter) in one: it answers with a fixed
 * response, counts its sends and keeps the last message, and its attachment
 * policy is configured (`attachments`: "none" for the base adapter's, "images"
 * for image attachments; limits and allowed types as given, null by default).
 * Its twin is `Fake` in crates/tools/compat/src/libs/agents.rs.
 */
final class Fake extends Adapter
{
    public int $sendCalls = 0;

    public ?Message $lastMessage = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function getName(): string
    {
        return $this->config['name'] ?? 'fake';
    }

    public function send(array $messages, ?callable $listener = null): Message
    {
        $this->sendCalls++;
        $last = end($messages);
        $this->lastMessage = $last instanceof Message ? $last : null;

        return new Message($this->config['response'] ?? '');
    }

    public function getModels(): array
    {
        return ['fake-model'];
    }

    public function getModel(): string
    {
        return 'fake-model';
    }

    public function setModel(string $model): self
    {
        return $this;
    }

    public function isSchemaSupported(): bool
    {
        return $this->config['schema'] ?? false;
    }

    public function getSupportForEmbeddings(): bool
    {
        return false;
    }

    public function embed(string $text): array
    {
        throw new \Exception('Embeddings not supported');
    }

    public function bulkEmbed(array $texts): array
    {
        throw new \Exception('Embeddings not supported');
    }

    public function getEmbeddingDimension(): int
    {
        return $this->config['dimension'] ?? throw new \Exception('Embeddings not supported');
    }

    public function supportsAttachments(): bool
    {
        return ($this->config['attachments'] ?? 'none') === 'images';
    }

    public function supportsAttachment(Message $attachment): bool
    {
        if (($this->config['attachments'] ?? 'none') !== 'images') {
            return parent::supportsAttachment($attachment);
        }
        $mimeType = $attachment->getMimeType();

        return $mimeType !== null && str_starts_with($mimeType, 'image/');
    }

    public function getMaxAttachmentsPerMessage(): ?int
    {
        return $this->config['maxAttachmentsPerMessage'] ?? null;
    }

    public function getMaxAttachmentBytes(): ?int
    {
        return $this->config['maxAttachmentBytes'] ?? null;
    }

    public function getMaxTotalAttachmentBytes(): ?int
    {
        return $this->config['maxTotalAttachmentBytes'] ?? null;
    }

    public function getAllowedAttachmentMimeTypes(): ?array
    {
        return $this->config['allowedAttachmentMimeTypes'] ?? null;
    }

    protected function formatErrorMessage($json): string
    {
        return 'fake error';
    }
}
