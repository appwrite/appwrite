<?php

namespace Utopia\Agents\Adapters;

use Utopia\Fetch\Chunk;

class XAI extends OpenAI
{
    /**
     * Default XAI API endpoint
     */
    protected const ENDPOINT = 'https://api.x.ai/v1/chat/completions';

    /**
     * Grok 3 - Latest Grok model
     */
    public const MODEL_GROK_3 = 'grok-3';

    /**
     * Grok 3 Mini - Mini version of grok 3
     */
    public const MODEL_GROK_3_MINI = 'grok-3-mini';

    /**
     * Grok 2 Image - Latest Grok model with image support
     */
    public const MODEL_GROK_2_IMAGE = 'grok-2-image-1212';

    /**
     * Create a new XAI adapter
     *
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_GROK_3_MINI,
        int $maxTokens = 1024,
        float $temperature = 1.0,
        ?string $endpoint = null,
        int $timeout = 90000
    ) {
        parent::__construct(
            $apiKey,
            $model,
            $maxTokens,
            $temperature,
            $endpoint ?? self::ENDPOINT,
            $timeout
        );
    }

    /**
     * Check if the model supports JSON schema
     */
    public function isSchemaSupported(): bool
    {
        return false;
    }

    /**
     * Get available models
     *
     * @return array<string>
     */
    public function getModels(): array
    {
        return [
            self::MODEL_GROK_3,
            self::MODEL_GROK_3_MINI,
            self::MODEL_GROK_2_IMAGE,
        ];
    }

    /**
     * Get the adapter name
     */
    public function getName(): string
    {
        return 'xai';
    }

    /**
     * Get support for embeddings
     */
    public function getSupportForEmbeddings(): bool
    {
        return false;
    }

    /**
     * Process a stream chunk from the OpenAI API
     *
     *
     * @throws \Exception
     */
    protected function process(Chunk $chunk, ?callable $listener): string
    {
        $block = '';
        [$data, $lines] = $this->prepareStreamLines($chunk);

        $json = $this->decodeJsonObject(trim($chunk->getData())) ?? $this->decodeJsonObject($data);
        if (is_array($json) && isset($json['error'])) {
            return $this->formatErrorMessage($json);
        }

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

        /** @var array<string, mixed> $json */
        $errorType = isset($json['code']) && is_scalar($json['code']) ? (string) $json['code'] : 'unknown_error';
        $errorMessage = isset($json['error']) && is_string($json['error']) ? $json['error'] : 'Unknown error';

        return '('.$errorType.') '.$errorMessage;
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
}
