<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Adapters\OpenRouter\Models as OpenRouterModels;

class ConversationOpenRouterTest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_OPENROUTER');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_OPENROUTER environment variable is not set');
        }

        return new OpenRouter(
            apiKey: $apiKey,
            model: OpenRouterModels::MODEL_OPENAI_GPT_4O,
            maxTokens: 1024,
            temperature: 1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test OpenRouter Agent Description';
    }
}
