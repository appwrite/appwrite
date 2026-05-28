<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapter;
use Utopia\Agents\Message;
use Utopia\Fetch\Client;

class Appwrite extends Adapter
{
    /**
     * NomicEmbedTextV15 - default general purpose text embedding model
     */
    public const MODEL_NOMIC_EMBED_TEXT = 'nomic-embed-text';

    /**
     * EmbeddingGemma300M - Gemma embedding model
     */
    public const MODEL_EMBEDDING_GEMMA = 'embedding-gemma';

    /**
     * AllMiniLML6V2 - small, fast sentence embedding model
     */
    public const MODEL_ALL_MINILM = 'all-minilm';

    /**
     * BGESmallENV15 - small English embedding model
     */
    public const MODEL_BGE_SMALL = 'bge-small';

    protected string $model;

    private string $endpoint = 'http://appwrite-embedding:11434/embed';

    public const MODELS = [
        self::MODEL_NOMIC_EMBED_TEXT,
        self::MODEL_EMBEDDING_GEMMA,
        self::MODEL_ALL_MINILM,
        self::MODEL_BGE_SMALL,
    ];

    /**
     * Embedding dimensions of specific embedding model
     */
    protected const DIMENSIONS = [
        self::MODEL_NOMIC_EMBED_TEXT => 768,
        self::MODEL_EMBEDDING_GEMMA => 768,
        self::MODEL_ALL_MINILM => 384,
        self::MODEL_BGE_SMALL => 384,
    ];

    /**
     * Create a new Appwrite embedding adapter (no API key required for local call)
     */
    public function __construct(
        string $model = self::MODEL_NOMIC_EMBED_TEXT,
        int $timeout = 90000
    ) {
        if (! in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException("Invalid model: {$model}. Supported models: ".implode(', ', self::MODELS));
        }

        $this->model = $model;
        $this->setTimeout($timeout);
    }

    /**
     * Embedding generation (the embedding service only supports embeddings, not chat)
     *
     * @return array{
     *     embedding: array<int, float>,
     *     tokensProcessed: int|null,
     *     totalDuration: int|null
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
            'texts' => [$text],
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

        // The service batches inputs; we send a single text and read the first embedding back.
        $embeddings = isset($json['embeddings']) && is_array($json['embeddings']) ? $json['embeddings'] : [];
        /** @var array<int, float> $firstEmbedding */
        $firstEmbedding = isset($embeddings[0]) && is_array($embeddings[0]) ? $embeddings[0] : [];

        return [
            'embedding' => $firstEmbedding,
            'tokensProcessed' => isset($json['tokens']) && is_int($json['tokens']) ? $json['tokens'] : null,
            'totalDuration' => isset($json['total_duration']) && is_int($json['total_duration']) ? $json['total_duration'] : null,
        ];
    }

    /**
     * Get available models for embeddings
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
        throw new \Exception('Appwrite does not support chat or messages. Use embed() instead.');
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
        return 'appwrite-embedding';
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
