<?php

namespace Utopia\Agents\Adapters;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Adapter;
use Utopia\Agents\Embedder;
use Utopia\Agents\Embeddings;
use Utopia\Agents\Exception\Api;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * Appwrite's self-hosted embedding service.
 */
class Appwrite extends Adapter implements Embedder
{
    /**
     * Nomic Embed Text v1.5, general purpose.
     */
    public const string MODEL_NOMIC_EMBED_TEXT = 'nomic-embed-text';

    /**
     * EmbeddingGemma 300M.
     */
    public const string MODEL_EMBEDDING_GEMMA = 'embedding-gemma';

    /**
     * all-MiniLM-L6-v2, small and fast.
     */
    public const string MODEL_ALL_MINILM = 'all-minilm';

    /**
     * BGE Small EN v1.5, small English model.
     */
    public const string MODEL_BGE_SMALL = 'bge-small';

    /**
     * Vector length of every model.
     */
    public const array DIMENSIONS = [
        self::MODEL_NOMIC_EMBED_TEXT => 768,
        self::MODEL_EMBEDDING_GEMMA => 768,
        self::MODEL_ALL_MINILM => 384,
        self::MODEL_BGE_SMALL => 384,
    ];

    protected const string ENDPOINT = 'http://appwrite-embedding:3000/embed';

    protected string $endpoint;

    public function __construct(
        protected string $model = self::MODEL_NOMIC_EMBED_TEXT,
        ?string $endpoint = null,
        int $timeout = 90000,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        if (! isset(self::DIMENSIONS[$model])) {
            throw new \InvalidArgumentException("Invalid model: {$model}. Supported models: ".implode(', ', array_keys(self::DIMENSIONS)));
        }

        $this->endpoint = $endpoint ?? static::ENDPOINT;
        $this->timeout = $timeout;
        $this->client = $client;
    }

    public function getName(): string
    {
        return 'appwrite';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * A copy of this embedder that uses $model, sharing its endpoint and client.
     */
    public function withModel(string $model): static
    {
        if (! isset(self::DIMENSIONS[$model])) {
            throw new \InvalidArgumentException("Invalid model: {$model}. Supported models: ".implode(', ', array_keys(self::DIMENSIONS)));
        }

        $clone = clone $this;
        $clone->model = $model;

        return $clone;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getDimension(): int
    {
        return self::DIMENSIONS[$this->model];
    }

    public function embed(array $texts): Embeddings
    {
        if ($texts === []) {
            throw new \InvalidArgumentException('Embedding needs at least one text');
        }

        $json = $this->post($this->endpoint, ['model' => $this->model, 'texts' => $texts]);

        $vectors = $this->vectors($json['embeddings'] ?? null);
        if (\count($vectors) !== \count($texts)) {
            throw new Api('Embedding count does not match the input count', $this->getName(), body: json_encode($json) ?: '');
        }

        return new Embeddings(
            $vectors,
            \is_int($json['tokens'] ?? null) ? $json['tokens'] : null,
            \is_int($json['total_duration'] ?? null) ? $json['total_duration'] : null,
        );
    }

    /**
     * @return list<list<float>>
     */
    protected function vectors(mixed $rows): array
    {
        if (! \is_array($rows)) {
            return [];
        }

        $vectors = [];
        foreach ($rows as $row) {
            if (! \is_array($row) || $row === []) {
                throw new Api('Embedding response has an empty or invalid vector', $this->getName());
            }
            $vectors[] = array_values(array_map(fn ($value) => \is_int($value) || \is_float($value) ? (float) $value : 0.0, $row));
        }

        return $vectors;
    }
}
