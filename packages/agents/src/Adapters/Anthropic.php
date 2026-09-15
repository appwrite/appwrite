<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;
use Utopia\Fetch\Chunk;
use Utopia\Fetch\Client;

class Anthropic extends Adapter
{
    protected const MAX_ATTACHMENTS_PER_MESSAGE = 10;

    protected const MAX_ATTACHMENT_BYTES = 5000000;

    protected const MAX_TOTAL_ATTACHMENT_BYTES = 20000000;

    /**
     * @var list<string>
     */
    protected const ALLOWED_ATTACHMENT_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
    ];

    /**
     * Claude 4 Opus - Flagship model with exceptional reasoning for the most demanding tasks
     */
    public const MODEL_CLAUDE_4_OPUS = 'claude-opus-4-0';

    /**
     * Claude 3 Opus - Premium model with superior performance on complex analysis and creative work
     */
    public const MODEL_CLAUDE_3_OPUS = 'claude-3-opus-latest';

    /**
     * Claude 4 Sonnet - Intelligent and responsive model optimized for productivity workflows
     */
    public const MODEL_CLAUDE_4_SONNET = 'claude-sonnet-4-0';

    /**
     * Claude 3.7 Sonnet - Enhanced model with improved reasoning and coding capabilities
     */
    public const MODEL_CLAUDE_3_7_SONNET = 'claude-3-7-sonnet-latest';

    /**
     * Claude 3.5 Sonnet - Versatile model balancing capability and speed for general use
     */
    public const MODEL_CLAUDE_3_5_SONNET = 'claude-3-5-sonnet-latest';

    /**
     * Claude 3.5 Haiku - Ultra-fast model for quick responses and lightweight processing
     */
    public const MODEL_CLAUDE_3_5_HAIKU = 'claude-3-5-haiku-latest';

    /**
     * Claude 3 Haiku - Rapid model designed for speed and efficiency on straightforward tasks
     */
    public const MODEL_CLAUDE_3_HAIKU = 'claude-3-haiku-20240307';

    /**
     * Cache TTL for 3600 seconds
     */
    private const CACHE_TTL_3600 = 'ephemeral';

    /**
     * Limit of instructions that can be cached
     */
    private const CACHE_LIMIT = 4;

    protected string $apiKey;

    protected string $model;

    protected int $maxTokens;

    protected float $temperature;

    /**
     * Create a new Anthropic adapter
     *
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_CLAUDE_3_HAIKU,
        int $maxTokens = 1024,
        float $temperature = 1.0,
        int $timeout = 90000
    ) {
        $this->apiKey = $apiKey;
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
        $this->timeout = $timeout;
        $this->setModel($model);
    }

    /**
     * Check if the model supports JSON schema
     */
    public function isSchemaSupported(): bool
    {
        return true;
    }

    /**
     * Send a message to the Anthropic API
     *
     * @param  array<Message>  $messages
     *
     * @throws \Exception
     */
    public function send(array $messages, ?callable $listener = null): Message
    {
        if ($this->getAgent() === null) {
            throw new \Exception('Agent not set');
        }

        $client = new Client();
        $client
            ->setTimeout($this->timeout)
            ->addHeader('x-api-key', $this->apiKey)
            ->addHeader('anthropic-version', '2023-06-01')
            ->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON);

        $systemMessages = [];
        if (! empty($this->getAgent()->getDescription())) {
            $systemMessages[] = [
                'type' => 'text',
                'text' => $this->getAgent()->getDescription(),
                'cache_control' => [
                    'type' => self::CACHE_TTL_3600,
                ],
            ];
        }

        $cacheControlCount = ! empty($this->getAgent()->getDescription()) ? 1 : 0;
        foreach ($this->getAgent()->getInstructions() as $name => $content) {
            $text = is_array($content) ? implode("\n", $content) : $content;
            $message = [
                'type' => 'text',
                'text' => '# '.$name."\n\n".$text,
            ];

            if ($cacheControlCount < self::CACHE_LIMIT) {
                $message['cache_control'] = [
                    'type' => self::CACHE_TTL_3600,
                ];
                $cacheControlCount++;
            }

            $systemMessages[] = $message;
        }

        $formattedMessages = [];
        foreach ($messages as $message) {
            $formattedMessages[] = [
                'role' => $message->getRole(),
                'content' => $this->formatMessageContent($message),
            ];
        }

        $schema = $this->getAgent()->getSchema();
        $payload = [
            'model' => $this->model,
            'system' => $systemMessages,
            'messages' => $formattedMessages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        if (isset($schema)) {
            $payload['tools'] = [
                [
                    'name' => $schema->getName(),
                    'description' => $schema->getDescription(),
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => $schema->getProperties(),
                        'required' => $schema->getRequired(),
                    ],
                ],
            ];
            $payload['tool_choice'] = [
                'type' => 'tool',
                'name' => $schema->getName(),
            ];
            $payload['stream'] = false;
        } else {
            $payload['stream'] = true;
        }

        $content = '';
        if ($payload['stream']) {
            $this->beginStreamProcessing();
            try {
                $response = $client->fetch(
                    'https://api.anthropic.com/v1/messages',
                    Client::METHOD_POST,
                    $payload,
                    [],
                    function ($chunk) use (&$content, $listener) {
                        /** @var Chunk $chunk */
                        $content .= $this->process($chunk, $listener);
                    }
                );
                $content .= $this->flushBufferedStreamData($listener);
            } finally {
                $this->endStreamProcessing();
            }
        } else {
            $response = $client->fetch(
                'https://api.anthropic.com/v1/messages',
                Client::METHOD_POST,
                $payload,
            );
        }

        if ($response->getStatusCode() >= 400) {
            if (! $payload['stream']) {
                $responseBody = $response->getBody();
                $json = is_string($responseBody) ? json_decode($responseBody, true) : null;
                $content = $this->formatErrorMessage($json);
            }

            throw new \Exception(
                ucfirst($this->getName()).' API error: '.$content,
                $response->getStatusCode()
            );
        }

        if ($payload['stream']) {
            return new Message($content);
        }

        $body = $response->getBody();
        $json = is_string($body) ? json_decode($body, true) : null;

        $text = '';
        if (is_array($json)) {
            $content = $json['content'] ?? null;
            if (is_array($content) && isset($content[0])) {
                $item = $content[0];
                if (is_array($item) &&
                    isset($item['type']) && $item['type'] === 'tool_use' &&
                    isset($item['name']) && $item['name'] === $schema->getName()) {
                    $text = is_string($item['input']) ? $item['input'] : (json_encode($item['input']) ?: '');
                }
            }
        }

        if ($text === '') {
            $text = is_string($body) ? $body : (is_array($json) ? (json_encode($json) ?: '') : '');
        }

        return new Message($text);
    }

    /**
     * @return array<int, array<string, mixed>>|string
     */
    protected function formatMessageContent(Message $message): array|string
    {
        $parts = [];

        if ($message->getContent() !== '') {
            $parts[] = [
                'type' => 'text',
                'text' => $message->getContent(),
            ];
        }

        foreach ($message->getAttachments() as $attachment) {
            if (! $this->isImageAttachment($attachment)) {
                continue;
            }

            $parts[] = $this->buildImagePart($attachment);
        }

        if (empty($parts)) {
            return $message->getContent();
        }

        if (count($parts) === 1 && isset($parts[0]['type']) && $parts[0]['type'] === 'text') {
            $text = $parts[0]['text'] ?? '';

            return is_string($text) ? $text : '';
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildImagePart(Message $image): array
    {
        $mimeType = $image->getMimeType() ?? 'application/octet-stream';
        $mediaType = str_starts_with($mimeType, 'image/') ? $mimeType : 'application/octet-stream';

        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => base64_encode($image->getContent()),
            ],
        ];
    }

    /**
     * Process a stream chunk from the Anthropic API
     *
     *
     * @throws \Exception
     */
    protected function process(Chunk $chunk, ?callable $listener): string
    {
        [, $lines] = $this->prepareStreamLines($chunk);

        return $this->processStreamLines($lines, $listener);
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function processStreamLines(array $lines, ?callable $listener): string
    {
        $block = '';

        foreach ($lines as $line) {
            $json = $this->decodeJsonOrSseLine($line);
            if (! is_array($json)) {
                continue;
            }

            $type = $json['type'] ?? null;
            if ($type === null) {
                continue;
            }

            switch ($type) {
                case 'message_start':
                    if (isset($json['message']) && is_array($json['message']) && isset($json['message']['usage']) && is_array($json['message']['usage'])) {
                        $usage = $json['message']['usage'];
                        if (isset($usage['input_tokens']) && is_int($usage['input_tokens'])) {
                            $this->countInputTokens($usage['input_tokens']);
                        }
                        if (isset($usage['output_tokens']) && is_int($usage['output_tokens'])) {
                            $this->countOutputTokens($usage['output_tokens']);
                        }
                        if (isset($usage['cache_creation_input_tokens']) && is_int($usage['cache_creation_input_tokens'])) {
                            $this->countCacheCreationInputTokens($usage['cache_creation_input_tokens']);
                        }
                        if (isset($usage['cache_read_input_tokens']) && is_int($usage['cache_read_input_tokens'])) {
                            $this->countCacheReadInputTokens($usage['cache_read_input_tokens']);
                        }
                    }
                    break;

                case 'content_block_start':
                    // Initialize content block
                    break;

                case 'content_block_delta':
                    if (! isset($json['delta']) || ! is_array($json['delta']) || ! isset($json['delta']['type'])) {
                        break;
                    }

                    $deltaType = $json['delta']['type'];
                    if ($deltaType === 'text_delta' && isset($json['delta']['text']) && is_string($json['delta']['text'])) {
                        $this->appendStreamToken($block, $json['delta']['text'], $listener);
                    }
                    break;

                case 'content_block_stop':
                    // End of content block
                    break;

                case 'message_delta':
                    if (isset($json['usage']) && is_array($json['usage'])) {
                        $usage = $json['usage'];
                        if (isset($usage['input_tokens']) && is_int($usage['input_tokens'])) {
                            $this->countInputTokens($usage['input_tokens']);
                        }
                        if (isset($usage['output_tokens']) && is_int($usage['output_tokens'])) {
                            $this->countOutputTokens($usage['output_tokens']);
                        }
                    }
                    break;

                case 'message_stop':
                    break;

                case 'error':
                    return $this->formatErrorMessage($json);
            }
        }

        return $block;
    }

    protected function flushBufferedStreamData(?callable $listener): string
    {
        $line = $this->consumeStreamBufferLine();
        if ($line === null) {
            return '';
        }

        return $this->processStreamLines([$line], $listener);
    }

    /**
     * Get available models
     *
     * @return array<string>
     */
    public function getModels(): array
    {
        return [
            self::MODEL_CLAUDE_4_OPUS,
            self::MODEL_CLAUDE_3_OPUS,
            self::MODEL_CLAUDE_4_SONNET,
            self::MODEL_CLAUDE_3_7_SONNET,
            self::MODEL_CLAUDE_3_5_SONNET,
            self::MODEL_CLAUDE_3_5_HAIKU,
            self::MODEL_CLAUDE_3_HAIKU,
        ];
    }

    /**
     * Get current model
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set model to use
     */
    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Set max tokens
     */
    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    /**
     * Set temperature
     */
    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    /**
     * Get the adapter name
     */
    public function getName(): string
    {
        return 'anthropic';
    }

    public function supportsAttachments(): bool
    {
        return true;
    }

    public function supportsAttachment(Message $attachment): bool
    {
        return $this->isImageAttachment($attachment);
    }

    public function getMaxAttachmentsPerMessage(): ?int
    {
        return self::MAX_ATTACHMENTS_PER_MESSAGE;
    }

    public function getMaxAttachmentBytes(): ?int
    {
        return self::MAX_ATTACHMENT_BYTES;
    }

    public function getMaxTotalAttachmentBytes(): ?int
    {
        return self::MAX_TOTAL_ATTACHMENT_BYTES;
    }

    /**
     * @return list<string>|null
     */
    public function getAllowedAttachmentMimeTypes(): ?array
    {
        return self::ALLOWED_ATTACHMENT_MIME_TYPES;
    }

    /**
     * Extract and format error information from API response
     *
     * @param  mixed  $json
     */
    protected function formatErrorMessage($json): string
    {
        if (! is_array($json)) {
            return '(unknown_error) Unknown error';
        }

        $error = isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
        $errorType = isset($error['type']) && \is_string($error['type']) ? $error['type'] : 'unknown_error';
        $errorMessage = isset($error['message']) && \is_string($error['message']) ? $error['message'] : 'Unknown error';

        return '('.$errorType.') '.$errorMessage;
    }

    public function getSupportForEmbeddings(): bool
    {
        return false;
    }

    /**
     * @return array{
     *     embedding: array<int, float>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null ,
     *     modelLoadingDuration: int|null
     * }
     */
    public function embed(string $text): array
    {
        throw new \Exception('Embeddings are not supported for this adapter.');
    }

    /**
     * @param  array<int, string>  $texts
     * @return array{
     *     embeddings: array<int, array<int, float>>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null
     * }
     */
    public function bulkEmbed(array $texts): array
    {
        throw new \Exception('Embeddings are not supported for this adapter.');
    }

    public function getEmbeddingDimension(): int
    {
        throw new \Exception('Embeddings are not supported for this adapter.');
    }
}
