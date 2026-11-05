<?php

namespace Utopia\Agents\Adapters;

/**
 * DeepSeek, over its OpenAI-compatible API. Reasoning models stream their
 * thinking as reasoning deltas.
 */
class Deepseek extends OpenAI
{
    protected const string ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public function getName(): string
    {
        return 'deepseek';
    }

    protected function tokens(): string
    {
        return 'max_tokens';
    }
}
