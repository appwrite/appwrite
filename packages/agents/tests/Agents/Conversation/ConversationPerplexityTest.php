<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\Perplexity;

class ConversationPerplexityTest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_PERPLEXITY');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_PERPLEXITY environment variable is not set');
        }

        return new Perplexity(
            $apiKey,
            Perplexity::MODEL_SONAR,
            1024,
            1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test Perplexity Agent Description';
    }
}
