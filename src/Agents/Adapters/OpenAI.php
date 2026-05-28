<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;
use Utopia\Agents\Schema;
use Utopia\Fetch\Chunk;
use Utopia\Fetch\Client;

class OpenAI extends Adapter
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
     * GPT-5 Nano - Small GPT-5 variant optimized for low latency and cost-sensitive workloads
     */
    public const MODEL_GPT_5_NANO = 'gpt-5-nano';

    /**
     * GPT-4.5 Preview - OpenAI's most advanced model with enhanced reasoning, broader knowledge, and improved instruction following
     */
    public const MODEL_GPT_4_5_PREVIEW = 'gpt-4.5-preview';

    /**
     * GPT-4.1 - Advanced large language model with strong reasoning capabilities and improved context handling
     */
    public const MODEL_GPT_4_1 = 'gpt-4.1';

    /**
     * GPT-4o - Multimodal model optimized for both text and image processing with faster response times
     */
    public const MODEL_GPT_4O = 'gpt-4o';

    /**
     * o4-mini - Compact version of GPT-4o offering good performance with higher throughput and lower latency
     */
    public const MODEL_O4_MINI = 'o4-mini';

    /**
     * o3 - Balanced model offering good performance for general language tasks with efficient resource usage
     */
    public const MODEL_O3 = 'o3';

    /**
     * o3-mini - Streamlined model optimized for speed and efficiency while maintaining good capabilities for routine tasks
     */
    public const MODEL_O3_MINI = 'o3-mini';

    /**
     * Default OpenAI API endpoint
     */
    protected const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    protected string $apiKey;

    protected string $model;

    protected int $maxTokens;

    protected float $temperature;

    protected string $endpoint;

    protected int $timeout;

    protected bool $hasWarnedTemperatureOverride = false;

    /**
     * Create a new OpenAI adapter
     *
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_O3_MINI,
        int $maxTokens = 1024,
        float $temperature = 1.0,
        ?string $endpoint = null,
        int $timeout = 90000
    ) {
        $this->apiKey = $apiKey;
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
        $this->endpoint = $endpoint ?? self::ENDPOINT;
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
     * Send a message to the API
     *
     * @param  array<Message>  $messages
     *
     * @throws \Exception
     */
    public function send(array $messages, ?callable $listener = null): Message
    {
        $agent = $this->getAgent();
        if ($agent === null) {
            throw new \Exception('Agent not set');
        }

        $client = $this->createClient();

        $formattedMessages = [];
        foreach ($messages as $message) {
            if (! $this->isMessageValid($message)) {
                throw new \Exception('Invalid message format');
            }
            $formattedMessages[] = [
                'role' => $message->getRole(),
                'content' => $this->formatMessageContent($message),
            ];
        }

        $instructions = [];
        foreach ($agent->getInstructions() as $name => $content) {
            $text = is_array($content) ? implode("\n", $content) : $content;
            $instructions[] = '# '.$name."\n\n".$text;
        }

        $systemMessage = $agent->getDescription().
            (empty($instructions) ? '' : "\n\n".implode("\n\n", $instructions));

        if (! empty($systemMessage)) {
            array_unshift($formattedMessages, [
                'role' => 'system',
                'content' => $systemMessage,
            ]);
        }

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
        ];
        $temperature = $this->temperature;
        if ($this->usesDefaultTemperatureOnly()) {
            $temperature = 1.0;
        }
        $payload['temperature'] = $temperature;

        $schema = $agent->getSchema();
        if ($schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schema->getName(),
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => $schema->getProperties(),
                        'required' => $schema->getRequired(),
                        'additionalProperties' => false,
                    ],
                ],
            ];
            $payload['stream'] = false;
        } else {
            $payload['stream'] = true;
        }

        if ($this->usesMaxCompletionTokens()) {
            $payload['max_completion_tokens'] = $this->maxTokens;
        } else {
            $payload['max_tokens'] = $this->maxTokens;
        }

        $content = '';

        if ($payload['stream']) {
            $this->beginStreamProcessing();
            try {
                $response = $client->fetch(
                    $this->endpoint,
                    Client::METHOD_POST,
                    $payload,
                    [],
                    function ($chunk) use (&$content, $listener) {
                        /** @var Chunk $chunk */
                        $content .= $this->process($chunk, $listener);
                    }
                );
                $content .= $this->flushBufferedStreamData($listener);

                if ($response->getStatusCode() >= 400) {
                    throw new \Exception(
                        ucfirst($this->getName()).' API error: '.$content,
                        $response->getStatusCode()
                    );
                }
            } finally {
                $this->endStreamProcessing();
            }
        } else {
            $response = $client->fetch(
                $this->endpoint,
                Client::METHOD_POST,
                $payload,
            );
            $body = $response->getBody();

            if ($response->getStatusCode() >= 400) {
                $json = is_string($body) ? json_decode($body, true) : null;
                $content = $this->formatErrorMessage($json);
                throw new \Exception(
                    ucfirst($this->getName()).' API error: '.$content,
                    $response->getStatusCode()
                );
            }

            $json = is_string($body) ? json_decode($body, true) : null;
            $choices = is_array($json) && isset($json['choices']) && is_array($json['choices']) ? $json['choices'] : [];
            $firstChoice = isset($choices[0]) && is_array($choices[0]) ? $choices[0] : [];
            $message = isset($firstChoice['message']) && is_array($firstChoice['message']) ? $firstChoice['message'] : [];
            if (isset($message['content']) && is_string($message['content'])) {
                $content = $message['content'];
            } else {
                throw new \Exception('Invalid response format received from the API');
            }
        }

        return new Message($content);
    }

    /**
     * Process a stream chunk from the OpenAI API
     *
     *
     * @throws \Exception
     */
    protected function process(Chunk $chunk, ?callable $listener): string
    {
        [$data, $lines] = $this->prepareStreamLines($chunk);

        $json = $this->decodeJsonObject(trim($chunk->getData())) ?? $this->decodeJsonObject($data);
        if (is_array($json) && isset($json['error'])) {
            return $this->formatErrorMessage($json);
        }

        return $this->processStreamLines($lines, $listener);
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function processStreamLines(array $lines, ?callable $listener): string
    {
        $block = '';

        foreach ($lines as $line) {
            $json = $this->decodeSseJsonLine($line);
            if (! is_array($json)) {
                continue;
            }

            // Extract content from the choices array
            $choices = isset($json['choices']) && is_array($json['choices']) ? $json['choices'] : [];
            $firstChoice = isset($choices[0]) && is_array($choices[0]) ? $choices[0] : [];
            $delta = isset($firstChoice['delta']) && is_array($firstChoice['delta']) ? $firstChoice['delta'] : [];
            if (isset($delta['content']) && is_string($delta['content'])) {
                $this->appendStreamToken($block, $delta['content'], $listener);
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
            self::MODEL_GPT_5_NANO,
            self::MODEL_GPT_4_5_PREVIEW,
            self::MODEL_GPT_4_1,
            self::MODEL_GPT_4O,
            self::MODEL_O4_MINI,
            self::MODEL_O3,
            self::MODEL_O3_MINI,
        ];
    }

    /**
     * OpenAI expects max_completion_tokens for these models.
     */
    protected function usesMaxCompletionTokens(): bool
    {
        $model = $this->normalizeModelForCompatibilityChecks();

        return in_array($model, [
            self::MODEL_GPT_5_NANO,
            self::MODEL_O4_MINI,
            self::MODEL_O3,
            self::MODEL_O3_MINI,
        ], true);
    }

    /**
     * Some models only accept the default temperature (1).
     */
    protected function usesDefaultTemperatureOnly(): bool
    {
        $model = $this->normalizeModelForCompatibilityChecks();
        $usesDefaultTemperatureOnly = in_array($model, [
            self::MODEL_GPT_5_NANO,
        ], true);

        if ($usesDefaultTemperatureOnly && $this->temperature !== 1.0 && ! $this->hasWarnedTemperatureOverride) {
            $this->hasWarnedTemperatureOverride = true;
            error_log(
                "OpenAI adapter warning: model '{$this->model}' only supports temperature=1.0. "
                ."Overriding provided value {$this->temperature}. "
                .'Set temperature to 1.0 to remove this warning.'
            );
        }

        return $usesDefaultTemperatureOnly;
    }

    protected function isMessageValid(Message $message): bool
    {
        return ! empty($message->getRole()) && $this->hasTextOrImageContent($message);
    }

    protected function hasTextOrImageContent(Message $message): bool
    {
        if ($message->getContent() !== '') {
            return true;
        }

        foreach ($message->getAttachments() as $attachment) {
            if ($this->isImageAttachment($attachment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|array<int, array<string, mixed>>
     */
    protected function formatMessageContent(Message $message): string|array
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
        $base64 = base64_encode($image->getContent());

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:'.$mimeType.';base64,'.$base64,
            ],
        ];
    }

    /**
     * Create a configured HTTP client for API requests.
     */
    protected function createClient(): Client
    {
        $client = new Client();
        $client
            ->setTimeout($this->timeout)
            ->addHeader('authorization', 'Bearer '.$this->apiKey)
            ->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON);

        return $client;
    }

    /**
     * Allow subclasses to normalize routed model IDs.
     */
    protected function normalizeModelForCompatibilityChecks(): string
    {
        return $this->model;
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
     * Get the API endpoint
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Set the API endpoint
     */
    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    /**
     * Get the adapter name
     */
    public function getName(): string
    {
        return 'openai';
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
        $errorType = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : 'unknown_error';
        $errorMessage = isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Unknown error';

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
