<?php

namespace Utopia\Agents\Adapters\OpenAI;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Adapter;
use Utopia\Agents\Embedder as Contract;
use Utopia\Agents\Embeddings;
use Utopia\Agents\Exception\Api;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * OpenAI embeddings, and compatible /v1/embeddings endpoints.
 */
class Embedder extends Adapter implements Contract
{
    /**
     * Native vector length of known models; pass $dimension to shorten
     * text-embedding-3 vectors or for other models.
     */
    public const array DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    protected const string ENDPOINT = 'https://api.openai.com/v1/embeddings';

    protected string $endpoint;

    protected int $dimension;

    /**
     * @param  int|null  $dimensions  Requested vector length; sent to the API when set
     */
    public function __construct(
        protected string $apiKey,
        protected string $model = 'text-embedding-3-small',
        protected ?int $dimensions = null,
        ?string $endpoint = null,
        int $timeout = 90000,
        (ClientInterface&StreamingClientInterface)|null $client = null,
    ) {
        $dimension = $dimensions ?? self::DIMENSIONS[$model] ?? null;
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
        return 'openai';
    }

    public function getModel(): string
    {
        return $this->model;
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

        $payload = ['model' => $this->model, 'input' => $texts, 'encoding_format' => 'float'];
        if ($this->dimensions !== null) {
            $payload['dimensions'] = $this->dimensions;
        }

        $json = $this->post($this->endpoint, $payload);

        $rows = \is_array($json['data'] ?? null) ? $json['data'] : [];
        $vectors = [];
        foreach ($rows as $position => $row) {
            $embedding = \is_array($row) && \is_array($row['embedding'] ?? null) ? $row['embedding'] : [];
            if ($embedding === []) {
                throw new Api('Embedding response has an empty or invalid vector', $this->getName());
            }
            $index = \is_array($row) && \is_int($row['index'] ?? null) ? $row['index'] : $position;
            $vectors[$index] = array_values(array_map(fn ($value) => \is_int($value) || \is_float($value) ? (float) $value : 0.0, $embedding));
        }
        ksort($vectors);
        $vectors = array_values($vectors);

        if (\count($vectors) !== \count($texts)) {
            throw new Api('Embedding count does not match the input count', $this->getName());
        }

        $usage = \is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return new Embeddings($vectors, \is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : null);
    }

    protected function headers(): array
    {
        return ['authorization' => 'Bearer '.$this->apiKey];
    }
}
