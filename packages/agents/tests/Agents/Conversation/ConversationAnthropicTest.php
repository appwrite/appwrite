<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\Anthropic;

class ConversationAnthropicTest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_ANTHROPIC');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_ANTHROPIC environment variable is not set');
        }

        return new Anthropic(
            $apiKey,
            Anthropic::MODEL_CLAUDE_3_HAIKU,
            1024,
            1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test Anthropic Agent Description';
    }
}
