<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;
use Utopia\Fetch\Chunk;
use Utopia\Fetch\Client;

class Gemini extends Adapter
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
     * Gemini 2.5 Flash Preview - Our best model in terms of price-performance, offering well-rounded capabilities.
     */
    public const MODEL_GEMINI_2_5_FLASH_PREVIEW = 'gemini-2.5-flash-preview-04-17';

    /**
     * Gemini 2.5 Pro Preview - Enhanced thinking and reasoning, multimodal understanding, advanced coding, and more.
     */
    public const MODEL_GEMINI_2_5_PRO_PREVIEW = 'gemini-2.5-pro-preview-03-25';

    /**
     * Gemini 2.0 Flash - Next generation features, speed, thinking, realtime streaming, and multimodal generation.
     */
    public const MODEL_GEMINI_2_0_FLASH = 'gemini-2.0-flash';

    /**
     * Gemini 2.0 Flash Lite - Cost efficiency and low latency.
     */
    public const MODEL_GEMINI_2_0_FLASH_LITE = 'gemini-2.0-flash-lite';

    /**
     * Gemini 1.5 Pro - Complex reasoning tasks requiring more intelligence.
     */
    public const MODEL_GEMINI_1_5_PRO = 'gemini-1.5-pro';

    protected string $apiKey;

    protected string $model;

    protected int $maxTokens;

    protected float $temperature;

    protected string $endpoint;

    protected int $timeout;

    /**
     * Create a new Gemini adapter
     *
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GEMINI_2_0_FLASH,
        int $maxTokens = 1024,
        float $temperature = 1.0,
        ?string $endpoint = null,
        int $timeout = 90000
    ) {
        $this->apiKey = $apiKey;
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
        $this->endpoint = $endpoint ?? 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':streamGenerateContent?alt=sse&key='.$apiKey;
        $this->timeout = $timeout;
        $this->setModel($model);
    }

    /**
     * Check if the model supports JSON schema
     */
    public function isSchemaSupported(): bool
    {
        return false;
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
        if ($this->getAgent() === null) {
            throw new \Exception('Agent not set');
        }

        $client = new Client();
        $client
            ->setTimeout($this->timeout)
            ->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON);

        $systemParts = [];
        $systemParts[] = [
            'text' => $this->getAgent()->getDescription(),
        ];

        foreach ($this->getAgent()->getInstructions() as $name => $content) {
            $text = is_array($content) ? implode("\n", $content) : $content;
            $systemParts[] = [
                'text' => '# '.$name."\n\n".$text,
            ];
        }

        $formattedMessages = [];
        foreach ($messages as $message) {
            $formattedMessages[] = [
                'role' => $message->getRole() === 'user' ? 'user' : 'model',
                'parts' => $this->formatMessageParts($message),
            ];
        }

        $payload = [
            'system_instruction' => [
                'parts' => $systemParts,
            ],
            'contents' => $formattedMessages,
            'generationConfig' => [
                'maxOutputTokens' => $this->maxTokens,
                'temperature' => $this->temperature,
            ],
        ];

        $content = '';
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
        } finally {
            $this->endStreamProcessing();
        }

        if ($response->getStatusCode() >= 400) {
            throw new \Exception(
                ucfirst($this->getName()).' API error: '.$content,
                $response->getStatusCode()
            );
        }

        $message = new Message($content);

        return $message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function formatMessageParts(Message $message): array
    {
        $parts = [];

        if ($message->getContent() !== '') {
            $parts[] = [
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
            $parts[] = [
                'text' => '',
            ];
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildImagePart(Message $image): array
    {
        $mimeType = $image->getMimeType() ?? 'application/octet-stream';

        return [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => base64_encode($image->getContent()),
            ],
        ];
    }

    /**
     * Process a stream chunk from the Gemini API
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

            // Extract content from Gemini response format
            $candidates = isset($json['candidates']) && is_array($json['candidates']) ? $json['candidates'] : [];
            $firstCandidate = isset($candidates[0]) && is_array($candidates[0]) ? $candidates[0] : [];
            $content = isset($firstCandidate['content']) && is_array($firstCandidate['content']) ? $firstCandidate['content'] : [];
            $parts = isset($content['parts']) && is_array($content['parts']) ? $content['parts'] : [];
            $firstPart = isset($parts[0]) && is_array($parts[0]) ? $parts[0] : [];
            if (isset($firstPart['text']) && is_string($firstPart['text'])) {
                $this->appendStreamToken($block, $firstPart['text'], $listener);
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
            self::MODEL_GEMINI_2_0_FLASH,
            self::MODEL_GEMINI_2_0_FLASH_LITE,
            self::MODEL_GEMINI_1_5_PRO,
            self::MODEL_GEMINI_2_5_FLASH_PREVIEW,
            self::MODEL_GEMINI_2_5_PRO_PREVIEW,
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
        return 'gemini';
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
        $errorType = isset($error['status']) && is_string($error['status']) ? $error['status'] : 'unknown_error';
        $errorMessage = isset($error['message']) && is_string($error['message']) ? $error['message'] : 'Unknown error';
        $errorDetails = isset($error['details']) ? (string) json_encode($error['details'], JSON_PRETTY_PRINT) : '';

        return '('.$errorType.') '.$errorMessage.PHP_EOL.$errorDetails;
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
