<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;
use Utopia\Fetch\Chunk;
use Utopia\Fetch\Client;

class Deepseek extends Adapter
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
     * Deepseek-Chat - Most powerful model
     */
    public const MODEL_DEEPSEEK_CHAT = 'deepseek-chat';

    /**
     * Deepseek-Coder - Specialized for code
     */
    public const MODEL_DEEPSEEK_CODER = 'deepseek-coder';

    protected string $apiKey;

    protected string $model;

    protected int $maxTokens;

    protected float $temperature;

    protected int $timeout;

    /**
     * Create a new Deepseek adapter
     *
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_DEEPSEEK_CHAT,
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
     * Send a message to the Deepseek API
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
            ->addHeader('authorization', 'Bearer '.$this->apiKey)
            ->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON);

        $formattedMessages = [];
        foreach ($messages as $message) {
            if (! empty($message->getRole()) && $this->hasTextOrImageContent($message)) {
                $formattedMessages[] = [
                    'role' => $message->getRole(),
                    'content' => $this->formatMessageContent($message),
                ];
            }
        }

        $instructions = [];
        foreach ($this->getAgent()->getInstructions() as $name => $content) {
            $text = is_array($content) ? implode("\n", $content) : $content;
            $instructions[] = '# '.$name."\n\n".$text;
        }

        $systemMessage = $this->getAgent()->getDescription().
            (empty($instructions) ? '' : "\n\n".implode("\n\n", $instructions));

        $schema = $this->getAgent()->getSchema();
        if ($schema !== null) {
            $systemMessage .= "\n\n"."USE THE JSON SCHEMA BELOW TO GENERATE A VALID JSON RESPONSE: \n".$schema->toJson();
        }

        if (! empty($systemMessage)) {
            array_unshift($formattedMessages, [
                'role' => 'system',
                'content' => $systemMessage,
            ]);
        }

        $payload = [
            'model' => $this->model,
            'messages' => $formattedMessages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'stream' => true,
        ];

        if ($schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_object',
            ];
        }

        $content = '';
        $this->beginStreamProcessing();
        try {
            $response = $client->fetch(
                'https://api.deepseek.com/chat/completions',
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

        return new Message($content);
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

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => 'data:'.$mimeType.';base64,'.base64_encode($image->getContent()),
            ],
        ];
    }

    /**
     * Process a stream chunk from the Deepseek API
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

            $choices = isset($json['choices']) && is_array($json['choices']) ? $json['choices'] : [];
            $firstChoice = isset($choices[0]) && is_array($choices[0]) ? $choices[0] : [];
            $delta = isset($firstChoice['delta']) && is_array($firstChoice['delta']) ? $firstChoice['delta'] : [];
            if (isset($delta['content']) && is_string($delta['content'])) {
                $this->appendStreamToken($block, $delta['content'], $listener);
            }

            if (isset($json['usage']) && is_array($json['usage'])) {
                $usage = $json['usage'];
                if (isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])) {
                    $this->countInputTokens($usage['prompt_tokens']);
                }
                if (isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])) {
                    $this->countOutputTokens($usage['completion_tokens']);
                }
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
            self::MODEL_DEEPSEEK_CHAT,
            self::MODEL_DEEPSEEK_CODER,
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
        return 'deepseek';
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
        $errorType = isset($error['type']) && is_string($error['type']) ? $error['type'] : 'unknown_error';
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
