<?php

namespace Utopia\Agents\Adapters;

/**
 * xAI Grok, over its OpenAI-compatible API.
 */
class XAI extends OpenAI
{
    protected const string ENDPOINT = 'https://api.x.ai/v1/chat/completions';

    public function getName(): string
    {
        return 'xai';
    }

    protected function tokens(): string
    {
        return 'max_tokens';
    }
}
