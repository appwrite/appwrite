<?php

namespace Utopia\Agents;

use Utopia\Agents\Roles\Assistant;

class Conversation
{
    /**
     * @var array<Message>
     */
    protected array $messages = [];

    protected Agent $agent;

    protected int $inputTokens = 0;

    protected int $outputTokens = 0;

    protected int $cacheCreationInputTokens = 0;

    protected int $cacheReadInputTokens = 0;

    protected int $totalTokens = 0;

    /**
     * @var callable
     */
    protected $listener;

    public function __construct(Agent $agent)
    {
        $this->agent = $agent;
        $this->listener = function () {};
    }

    /**
     * Set a callback to handle chunks
     */
    public function listen(callable $listener): self
    {
        $this->listener = $listener;

        return $this;
    }

    /**
     * Add a message to the conversation
     *
     * @param  array<int, mixed>  $attachments
     */
    public function message(Role $from, Message $message, array $attachments = []): self
    {
        $entry = $message->withRole($from->getIdentifier());
        $normalizedExistingAttachments = [];
        foreach ($entry->getAttachments() as $existingAttachment) {
            $normalizedExistingAttachments[] = $existingAttachment->withRole($from->getIdentifier());
        }
        $entry->setAttachments($normalizedExistingAttachments);

        $this->validateAttachments($entry, $attachments);

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof Message) {
                throw new \InvalidArgumentException('Attachments must be Message instances');
            }

            $entry->addAttachment($attachment->withRole($from->getIdentifier()));
        }
        $this->messages[] = $entry;

        return $this;
    }

    /**
     * Send the conversation to the agent and get response
     *
     *
     * @throws \Exception
     */
    public function send(): Message
    {
        $message = $this->agent->getAdapter()->send($this->messages, $this->listener);

        $this->countInputTokens($this->agent->getAdapter()->getInputTokens());
        $this->countOutputTokens($this->agent->getAdapter()->getOutputTokens());
        $this->countCacheCreationInputTokens($this->agent->getAdapter()->getCacheCreationInputTokens());
        $this->countCacheReadInputTokens($this->agent->getAdapter()->getCacheReadInputTokens());

        $from = new Assistant($this->agent->getAdapter()->getModel(), 'Assistant');
        $this->message($from, $message);

        return $message;
    }

    /**
     * Get all messages in the conversation
     *
     * @return array<Message>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Get the agent in the conversation
     */
    public function getAgent(): Agent
    {
        return $this->agent;
    }

    /**
     * Get the current listener callback
     */
    public function getListener(): callable
    {
        return $this->listener;
    }

    /**
     * Get input tokens count
     */
    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Add to input tokens count
     */
    public function countInputTokens(int $tokens): self
    {
        $this->inputTokens += $tokens;

        return $this;
    }

    /**
     * Get output tokens count
     */
    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    /**
     * Add to output tokens count
     */
    public function countOutputTokens(int $tokens): self
    {
        $this->outputTokens += $tokens;

        return $this;
    }

    /**
     * Get cache creation input tokens count
     */
    public function getCacheCreationInputTokens(): int
    {
        return $this->cacheCreationInputTokens;
    }

    /**
     * Add to cache creation input tokens count
     */
    public function countCacheCreationInputTokens(int $tokens): self
    {
        $this->cacheCreationInputTokens += $tokens;

        return $this;
    }

    /**
     * Get cache read input tokens count
     */
    public function getCacheReadInputTokens(): int
    {
        return $this->cacheReadInputTokens;
    }

    /**
     * Add to cache read input tokens count
     */
    public function countCacheReadInputTokens(int $tokens): self
    {
        $this->cacheReadInputTokens += $tokens;

        return $this;
    }

    /**
     * Get total tokens count
     */
    public function getTotalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheCreationInputTokens + $this->cacheReadInputTokens;
    }

    /**
     * @param  array<int, mixed>  $attachments
     */
    protected function validateAttachments(Message $message, array $attachments): void
    {
        $adapter = $this->agent->getAdapter();
        $allAttachments = array_merge($message->getAttachments(), $attachments);

        $maxAttachmentsPerMessage = $adapter->getMaxAttachmentsPerMessage();
        if ($maxAttachmentsPerMessage !== null && count($allAttachments) > $maxAttachmentsPerMessage) {
            throw new \InvalidArgumentException('Too many attachments in this message');
        }

        $maxAttachmentBytes = $adapter->getMaxAttachmentBytes();
        $maxTotalAttachmentBytes = $adapter->getMaxTotalAttachmentBytes();
        $allowedAttachmentMimeTypes = $adapter->getAllowedAttachmentMimeTypes();

        $totalBytes = 0;
        foreach ($allAttachments as $attachment) {
            if (! $attachment instanceof Message) {
                throw new \InvalidArgumentException('Attachments must be Message instances');
            }

            $bytes = strlen($attachment->getContent());
            if ($bytes === 0) {
                throw new \InvalidArgumentException('Attachment payload cannot be empty');
            }

            if ($maxAttachmentBytes !== null && $bytes > $maxAttachmentBytes) {
                throw new \InvalidArgumentException('Attachment exceeds per-file size limit');
            }

            $mimeType = $attachment->getMimeType();
            if ($mimeType === null) {
                throw new \InvalidArgumentException('Attachment MIME type cannot be detected');
            }

            if (
                $allowedAttachmentMimeTypes !== null &&
                ! in_array($mimeType, $allowedAttachmentMimeTypes, true)
            ) {
                throw new \InvalidArgumentException('Attachment MIME type is not allowed');
            }

            if (! $adapter->supportsAttachment($attachment)) {
                throw new \InvalidArgumentException('Attachment type is not supported by this adapter');
            }

            $totalBytes += $bytes;
        }

        if ($maxTotalAttachmentBytes !== null && $totalBytes > $maxTotalAttachmentBytes) {
            throw new \InvalidArgumentException('Attachments exceed total payload size limit');
        }
    }
}
