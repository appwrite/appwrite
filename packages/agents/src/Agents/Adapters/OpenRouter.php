<?php

namespace Utopia\Agents\Adapters;

use Utopia\Agents\Adapters\OpenRouter\Models as OpenRouterModels;
use Utopia\Fetch\Client;

class OpenRouter extends OpenAI
{
    /**
     * Default OpenRouter API endpoint
     */
    protected const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    protected ?string $httpReferer;

    protected ?string $xTitle;

    /**
     * Create a new OpenRouter adapter
     *
     * @throws \Exception
     */
    public function __construct(
        string $apiKey,
        string $model = OpenRouterModels::MODEL_OPENAI_GPT_4O,
        int $maxTokens = 1024,
        float $temperature = 1.0,
        ?string $endpoint = null,
        int $timeout = 90000,
        ?string $httpReferer = null,
        ?string $xTitle = null
    ) {
        $this->httpReferer = $httpReferer;
        $this->xTitle = $xTitle;

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
     * Get available models
     *
     * @return array<string>
     */
    public function getModels(): array
    {
        return OpenRouterModels::MODELS;
    }

    /**
     * Get the adapter name
     */
    public function getName(): string
    {
        return 'openrouter';
    }

    /**
     * Create a configured HTTP client for OpenRouter requests.
     */
    protected function createClient(): Client
    {
        $client = parent::createClient();

        if (! empty($this->httpReferer)) {
            $client->addHeader('HTTP-Referer', $this->httpReferer);
        }

        if (! empty($this->xTitle)) {
            $client->addHeader('X-Title', $this->xTitle);
        }

        return $client;
    }

    /**
     * OpenRouter routes to many providers — schema support is not guaranteed.
     */
    public function isSchemaSupported(): bool
    {
        return false;
    }

    /**
     * Strip provider prefixes and colon suffixes from routed model IDs
     * before OpenAI-specific checks (e.g. openai/gpt-5-nano:free → gpt-5-nano).
     */
    protected function normalizeModelForCompatibilityChecks(): string
    {
        $name = explode('/', $this->model, 2)[1] ?? $this->model;

        $colonPos = strpos($name, ':');

        return $colonPos !== false ? substr($name, 0, $colonPos) : $name;
    }
}
