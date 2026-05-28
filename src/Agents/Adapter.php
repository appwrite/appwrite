<?php

namespace Utopia\Agents;

use Utopia\Fetch\Chunk;

abstract class Adapter
{
    /**
     * Upper bound for retained incomplete SSE fragments.
     */
    protected const STREAM_BUFFER_MAX_BYTES = 1048576;

    /**
     * The agent instance
     */
    protected ?Agent $agent = null;

    /**
     * Input tokens count
     */
    protected int $inputTokens = 0;

    /**
     * Output tokens count
     */
    protected int $outputTokens = 0;

    /**
     * Cache creation input tokens count
     */
    protected int $cacheCreationInputTokens = 0;

    /**
     * Cache read input tokens count
     */
    protected int $cacheReadInputTokens = 0;

    /**
     * Request timeout in milliseconds
     */
    protected int $timeout = 90000;

    /**
     * Carries incomplete SSE line fragments between chunks.
     */
    protected string $streamBuffer = '';

    /**
     * Get the adapter name
     */
    abstract public function getName(): string;

    /**
     * Send a message to the AI model
     *
     * @param  array<Message>  $messages  The messages to send to the AI model
     * @param  callable|null  $listener  The listener to call when the message is sent
     * @return Message Response from the AI model
     *
     * @throws \Exception
     */
    abstract public function send(array $messages, ?callable $listener = null): Message;

    /**
     * Get available models for this adapter
     *
     * @return array<string>
     */
    abstract public function getModels(): array;

    /**
     * Get the currently selected model
     */
    abstract public function getModel(): string;

    /**
     * Set the model to use
     */
    abstract public function setModel(string $model): self;

    /**
     * Check if the model supports JSON schema
     */
    abstract public function isSchemaSupported(): bool;

    /**
     * Does this adapter support embeddings?
     */
    abstract public function getSupportForEmbeddings(): bool;

    /**
     * Generate embedding for input text (must be implemented if getSupportForEmbeddings is true)
     *
     * @return array{
     *     embedding: array<int, float>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null ,
     *     modelLoadingDuration?: int|null
     * }
     */
    abstract public function embed(string $text): array;

    /**
     * Generate embeddings for a batch of texts (must be implemented if getSupportForEmbeddings is true).
     *
     * @param  array<int, string>  $texts
     * @return array{
     *     embeddings: array<int, array<int, float>>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null
     * }
     *
     * @throws \Exception
     */
    abstract public function bulkEmbed(array $texts): array;

    /**
     * get embedding dimenion of the current model
     */
    abstract public function getEmbeddingDimension(): int;

    /**
     * Format error message
     *
     * @param  mixed  $json
     */
    abstract protected function formatErrorMessage($json): string;

    /**
     * Whether this adapter can accept message attachments.
     */
    public function supportsAttachments(): bool
    {
        return false;
    }

    /**
     * Whether a specific attachment message type is supported.
     */
    public function supportsAttachment(Message $attachment): bool
    {
        return $this->supportsAttachments();
    }

    protected function isImageAttachment(Message $attachment): bool
    {
        if ($attachment->getContent() === '') {
            return false;
        }

        $mimeType = $attachment->getMimeType();

        return $mimeType !== null && str_starts_with($mimeType, 'image/');
    }

    /**
     * Maximum attachments allowed per single conversation message.
     * Null means adapter does not set this limit.
     */
    public function getMaxAttachmentsPerMessage(): ?int
    {
        return null;
    }

    /**
     * Maximum bytes allowed for a single attachment.
     * Null means adapter does not set this limit.
     */
    public function getMaxAttachmentBytes(): ?int
    {
        return null;
    }

    /**
     * Maximum total attachment bytes allowed per message turn.
     * Null means adapter does not set this limit.
     */
    public function getMaxTotalAttachmentBytes(): ?int
    {
        return null;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedAttachmentMimeTypes(): ?array
    {
        return null;
    }

    /**
     * Get the current agent
     */
    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    /**
     * Set the agent
     */
    public function setAgent(Agent $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    /**
     * Get input tokens count
     */
    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Get output tokens count
     */
    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    /**
     * Get cache creation input tokens count
     */
    public function getCacheCreationInputTokens(): int
    {
        return $this->cacheCreationInputTokens;
    }

    /**
     * Get cache read input tokens count
     */
    public function getCacheReadInputTokens(): int
    {
        return $this->cacheReadInputTokens;
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
     * Add to output tokens count
     */
    public function countOutputTokens(int $tokens): self
    {
        $this->outputTokens += $tokens;

        return $this;
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
     * Set timeout in milliseconds
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Get timeout in milliseconds
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Prepare stream state before processing a new stream.
     */
    protected function beginStreamProcessing(): void
    {
        $this->resetStreamBuffer();
    }

    /**
     * Finalize stream state after stream processing ends.
     */
    protected function endStreamProcessing(): void
    {
        $this->resetStreamBuffer();
    }

    /**
     * Clear retained partial stream fragments.
     */
    protected function resetStreamBuffer(): void
    {
        $this->streamBuffer = '';
    }

    /**
     * Consume any remaining buffered stream fragment as a complete line.
     */
    protected function consumeStreamBufferLine(): ?string
    {
        if ($this->streamBuffer === '') {
            return null;
        }

        $line = $this->streamBuffer;
        $this->streamBuffer = '';

        return $line;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    protected function prepareStreamLines(Chunk $chunk): array
    {
        $combined = $this->streamBuffer.$chunk->getData();
        $lines = explode("\n", $combined);

        if ($combined !== '' && ! str_ends_with($combined, "\n")) {
            $this->streamBuffer = (string) array_pop($lines);
            if (strlen($this->streamBuffer) > self::STREAM_BUFFER_MAX_BYTES) {
                $this->streamBuffer = substr($this->streamBuffer, -self::STREAM_BUFFER_MAX_BYTES);
            }
        } else {
            $this->streamBuffer = '';
        }

        // Return only complete lines data (excluding buffered trailing fragment).
        return [implode("\n", $lines), $lines];
    }

    /**
     * Decode a standard SSE "data: {json}" line.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeSseJsonLine(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        if (! str_starts_with($line, 'data: ')) {
            return null;
        }

        $payload = substr($line, 6);
        if (trim($payload) === '[DONE]') {
            return null;
        }

        $json = $this->decodeJsonObject($payload);

        return $json;
    }

    /**
     * Decode either raw JSON lines or SSE "data: {json}" lines.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeJsonOrSseLine(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }

        $payload = str_starts_with($line, 'data: ') ? substr($line, 6) : $line;
        if (trim($payload) === '[DONE]') {
            return null;
        }

        $json = $this->decodeJsonObject($payload);

        return $json;
    }

    protected function appendStreamToken(string &$block, string $token, ?callable $listener): void
    {
        if ($token === '') {
            return;
        }

        $block .= $token;
        if ($listener !== null) {
            $listener($token);
        }
    }

    /**
     * Decode only JSON objects (associative arrays with string keys).
     *
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $jsonString): ?array
    {
        $decoded = json_decode($jsonString, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
