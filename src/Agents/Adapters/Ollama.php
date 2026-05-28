<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;
use Utopia\Fetch\Client;

class Ollama extends Adapter
{
    /**
     * EmbeddingGemma - Gemma embedding model for Ollama
     */
    public const MODEL_EMBEDDING_GEMMA = 'embeddinggemma';

    protected string $model;

    private string $endpoint = 'http://ollama:11434/api/embed';

    public const MODELS = [self::MODEL_EMBEDDING_GEMMA];

    /**
     * Embedding dimensions of specific embedding model
     */
    protected const DIMENSIONS = [
        self::MODEL_EMBEDDING_GEMMA => 768,
    ];

    /**
     * Create a new Ollama adapter (no API key required for local call)
     */
    public function __construct(
        string $model = self::MODEL_EMBEDDING_GEMMA,
        int $timeout = 90000
    ) {
        if (! in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException("Invalid model: {$model}. Supported models: ".implode(', ', self::MODELS));
        }

        $this->model = $model;
        $this->setTimeout($timeout);
    }

    /**
     * Embedding generation (Ollama only supports embeddings, not chat)
     *
     * @return array{
     *     embedding: array<int, float>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null ,
     *     modelLoadingDuration: int|null
     * }
     *
     * @throws \Exception
     */
    public function embed(string $text): array
    {
        $client = new Client();
        $client->setTimeout($this->timeout);
        $client->addHeader('Content-Type', 'application/json');
        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];
        $response = $client->fetch(
            $this->getEndpoint(),
            Client::METHOD_POST,
            $payload
        );
        $body = $response->getBody();
        $json = is_string($body) ? json_decode($body, true) : null;

        if (! is_array($json)) {
            throw new \Exception('Invalid response format received from the API');
        }

        if (isset($json['error'])) {
            throw new \Exception(is_string($json['error']) ? $json['error'] : 'Unknown error', $response->getStatusCode());
        }

        // totalDuration is entire duration including the modelLoadingDuration
        $embeddings = isset($json['embeddings']) && is_array($json['embeddings']) ? $json['embeddings'] : [];
        /** @var array<int, float> $firstEmbedding */
        $firstEmbedding = isset($embeddings[0]) && is_array($embeddings[0]) ? $embeddings[0] : [];

        return [
            'embedding' => $firstEmbedding,
            'tokensProcessed' => isset($json['prompt_eval_count']) && is_int($json['prompt_eval_count']) ? $json['prompt_eval_count'] : null,
            'totalDuration' => isset($json['total_duration']) && is_int($json['total_duration']) ? $json['total_duration'] : null,
            'modelLoadingDuration' => isset($json['load_duration']) && is_int($json['load_duration']) ? $json['load_duration'] : null,
        ];
    }

    /**
     * Batch embedding for Ollama — its embed endpoint takes one input, so loop and aggregate.
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
    public function bulkEmbed(array $texts): array
    {
        if ($texts === []) {
            throw new \InvalidArgumentException('bulkEmbed requires at least one text');
        }

        $embeddings = [];
        $tokens = null;
        $duration = null;

        foreach ($texts as $text) {
            $result = $this->embed($text);
            $embeddings[] = $result['embedding'];
            if (isset($result['tokensProcessed'])) {
                $tokens = ($tokens ?? 0) + $result['tokensProcessed'];
            }
            if (isset($result['totalDuration'])) {
                $duration = ($duration ?? 0) + $result['totalDuration'];
            }
        }

        return [
            'embeddings' => $embeddings,
            'tokensProcessed' => $tokens,
            'totalDuration' => $duration,
        ];
    }

    /**
     * Get available models for embeddings (for now, only embeddinggemma)
     *
     * @return array<string>
     */
    public function getModels(): array
    {
        return self::MODELS;
    }

    /**
     * Get currently selected embedding model
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * get embedding dimenion of the current model
     */
    public function getEmbeddingDimension(): int
    {
        return self::DIMENSIONS[$this->model];
    }

    /**
     * Set model to use for embedding
     */
    public function setModel(string $model): self
    {
        if (! in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException("Invalid model: {$model}. Supported models: ".implode(', ', self::MODELS));
        }
        $this->model = $model;

        return $this;
    }

    /**
     * Not applicable for embedding-only adapters.
     *
     * @param  array<\Utopia\Agents\Message>  $messages
     *
     * @throws \Exception
     */
    public function send(array $messages, ?callable $listener = null): Message
    {
        throw new \Exception('OllamaAdapter does not support chat or messages. Use embed() instead.');
    }

    /**
     * Embeddings do not support schema.
     */
    public function isSchemaSupported(): bool
    {
        return false;
    }

    /**
     * Get the adapter name
     */
    public function getName(): string
    {
        return 'ollama';
    }

    /**
     * Error formatter (minimal)
     *
     * @param  mixed  $json
     */
    protected function formatErrorMessage($json): string
    {
        if (! is_array($json)) {
            return '(unknown_error) Unknown error';
        }

        $errorValue = $json['error'] ?? ($json['message'] ?? 'Unknown error');

        return is_string($errorValue) ? $errorValue : 'Unknown error';
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

    public function getSupportForEmbeddings(): bool
    {
        return true;
    }
}
