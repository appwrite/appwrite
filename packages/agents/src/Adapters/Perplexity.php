<?php

namespace Utopia\Agents\Adapters;

use Utopia\Fetch\Chunk;

class Perplexity extends OpenAI
{
    /**
     * Default Perplexity API endpoint
     */
    protected const ENDPOINT = 'https://api.perplexity.ai/chat/completions';

    /**
     * Sonar - Lightweight, cost-effective search model
     */
    public const MODEL_SONAR = 'sonar';

    /**
     * Sonar Pro - Enhanced search model with advanced features
     */
    public const MODEL_SONAR_PRO = 'sonar-pro';

    /**
     * Sonar Deep Research - Advanced search model with deep analysis capabilities
     */
    public const MODEL_SONAR_DEEP_RESEARCH = 'sonar-deep-research';

    /**
     * Sonar Reasoning - Reasoning model with analysis capabilities
     */
    public const MODEL_SONAR_REASONING = 'sonar-reasoning';

    /**
     * Sonar Reasoning Pro - Enhanced reasoning model with advanced features
     */
    public const MODEL_SONAR_REASONING_PRO = 'sonar-reasoning-pro';

    /**
     * Create a new Perplexity adapter
     *
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = self::MODEL_SONAR,
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
        return true;
    }

    /**
     * Get available models
     *
     * @return array<string>
     */
    public function getModels(): array
    {
        return [
            self::MODEL_SONAR,
            self::MODEL_SONAR_PRO,
            self::MODEL_SONAR_DEEP_RESEARCH,
            self::MODEL_SONAR_REASONING,
            self::MODEL_SONAR_REASONING_PRO,
        ];
    }

    /**
     * Get the adapter name
     */
    public function getName(): string
    {
        return 'perplexity';
    }

    /**
     * Get support for embeddings
     */
    public function getSupportForEmbeddings(): bool
    {
        return false;
    }

    /**
     * Process a stream chunk from the Perplexity API
     *
     *
     * @throws \Exception
     */
    protected function process(Chunk $chunk, ?callable $listener): string
    {
        $block = '';
        [$data, $lines] = $this->prepareStreamLines($chunk);

        $rawData = $chunk->getData();
        $json = $this->decodeJsonObject(trim($rawData)) ?? $this->decodeJsonObject($data);
        if (is_array($json) && isset($json['error'])) {
            return $this->formatErrorMessage($json);
        }

        // Specifically for Authorization and similar errors that return HTML
        $trimmed = ltrim($rawData);
        if (
            stripos($trimmed, '<html') === 0 ||
            stripos($trimmed, '<!DOCTYPE html') === 0
        ) {
            return $this->sanitizeHtmlError($rawData);
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
     * Sanitize HTML error responses into readable error messages
     */
    protected function sanitizeHtmlError(string $html): string
    {
        // Try to extract the error from the title tag
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches)) {
            $errorMessage = trim($matches[1]);

            // Extract status code and message if present (e.g., "401 Authorization Required")
            if (preg_match('/^(\d{3})\s+(.+)$/i', $errorMessage, $parts)) {
                $statusCode = $parts[1];
                $message = $parts[2];

                return '(http_'.$statusCode.') '.$message;
            }

            return '(html_error) '.$errorMessage;
        }

        // Try to extract from h1 tag
        if (preg_match('/<h1>(.*?)<\/h1>/is', $html, $matches)) {
            $errorMessage = trim(strip_tags($matches[1]));

            if (preg_match('/^(\d{3})\s+(.+)$/i', $errorMessage, $parts)) {
                $statusCode = $parts[1];
                $message = $parts[2];

                return '(http_'.$statusCode.') '.$message;
            }

            return '(html_error) '.$errorMessage;
        }

        // Fallback for unrecognized HTML errors
        return '(html_error) Received HTML error response from API';
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
