<?php

namespace Utopia\Agents\Adapters;

/**
 * Perplexity Sonar, over its OpenAI-compatible API.
 */
class Perplexity extends OpenAI
{
    protected const string ENDPOINT = 'https://api.perplexity.ai/chat/completions';

    public function getName(): string
    {
        return 'perplexity';
    }

    protected function tokens(): string
    {
        return 'max_tokens';
    }
}
