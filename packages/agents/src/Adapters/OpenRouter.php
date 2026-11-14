<?php

namespace Utopia\Agents\Adapters;

use Psr\Http\Client\ClientInterface;
use Utopia\Agents\Request;
use Utopia\Client\Psr18\StreamingClientInterface;

/**
 * OpenRouter, routing to any provider over an OpenAI-compatible API. Models
 * are addressed as "provider/model", such as "anthropic/claude-sonnet-4.5".
 */
class OpenRouter extends OpenAI
{
    protected const string ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * @param  string|null  $referer  Your site URL, for OpenRouter rankings
     * @param  string|null  $title  Your app name, for OpenRouter rankings
     */
    public function __construct(
        string $apiKey,
        string $model,
        ?string $endpoint = null,
        int $timeout = 90000,
        (ClientInterface&StreamingClientInterface)|null $client = null,
        protected ?string $referer = null,
        protected ?string $title = null,
    ) {
        parent::__construct($apiKey, $model, $endpoint, $timeout, $client);
    }

    public function getName(): string
    {
        return 'openrouter';
    }

    protected function tokens(): string
    {
        return 'max_tokens';
    }

    /**
     * OpenRouter takes reasoning effort as {"reasoning": {"effort": ...}}.
     */
    protected function payload(Request $request): array
    {
        $payload = parent::payload($request);

        if (isset($payload['reasoning_effort'])) {
            $payload['reasoning'] = ['effort' => $payload['reasoning_effort']] + (\is_array($payload['reasoning'] ?? null) ? $payload['reasoning'] : []);
            unset($payload['reasoning_effort']);
        }

        return $payload;
    }

    protected function headers(): array
    {
        $headers = parent::headers();

        if (! empty($this->referer)) {
            $headers['HTTP-Referer'] = $this->referer;
        }
        if (! empty($this->title)) {
            $headers['X-Title'] = $this->title;
        }

        return $headers;
    }
}
