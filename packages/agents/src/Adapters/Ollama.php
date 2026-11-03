<?php

namespace Utopia\Agents\Adapters;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Adapter;
use Utopia\Agents\Embedder;
use Utopia\Agents\Embeddings;
use Utopia\Agents\Exception\Api;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * Embeddings from an Ollama server.
 */
class Ollama extends Adapter implements Embedder
{
    public const string MODEL_EMBEDDING_GEMMA = 'embeddinggemma';

    /**
     * Vector length of known models; pass $dimension for others.
     */
    public const array DIMENSIONS = [
        self::MODEL_EMBEDDING_GEMMA => 768,
        'nomic-embed-text' => 768,
        'mxbai-embed-large' => 1024,
        'all-minilm' => 384,
    ];

    protected const string ENDPOINT = 'http://ollama:11434/api/embed';

    protected string $endpoint;

    protected int $dimension;

    public function __construct(
        protected string $model = self::MODEL_EMBEDDING_GEMMA,
        ?int $dimension = null,
        ?string $endpoint = null,
        int $timeout = 90000,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $dimension ??= self::DIMENSIONS[$model] ?? null;
        if ($dimension === null) {
            throw new \InvalidArgumentException("Unknown dimension for model {$model}; pass it explicitly");
        }

        $this->dimension = $dimension;
        $this->endpoint = $endpoint ?? static::ENDPOINT;
        $this->timeout = $timeout;
        $this->client = $client;
    }

    public function getName(): string
    {
        return 'ollama';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getDimension(): int
    {
        return $this->dimension;
    }

    public function embed(array $texts): Embeddings
    {
        if ($texts === []) {
            throw new \InvalidArgumentException('Embedding needs at least one text');
        }

        $json = $this->post($this->endpoint, ['model' => $this->model, 'input' => $texts]);

        $rows = \is_array($json['embeddings'] ?? null) ? $json['embeddings'] : [];
        $vectors = [];
        foreach ($rows as $row) {
            if (! \is_array($row) || $row === []) {
                throw new Api('Embedding response has an empty or invalid vector', $this->getName());
            }
            $vectors[] = array_values(array_map(fn ($value) => \is_int($value) || \is_float($value) ? (float) $value : 0.0, $row));
        }

        if (\count($vectors) !== \count($texts)) {
            throw new Api('Embedding count does not match the input count', $this->getName());
        }

        return new Embeddings(
            $vectors,
            \is_int($json['prompt_eval_count'] ?? null) ? $json['prompt_eval_count'] : null,
            \is_int($json['total_duration'] ?? null) ? $json['total_duration'] : null,
        );
    }
}
