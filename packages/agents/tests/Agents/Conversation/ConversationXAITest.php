<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\XAI;

class ConversationXAITest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_XAI');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_XAI environment variable is not set');
        }

        return new XAI(
            $apiKey,
            XAI::MODEL_GROK_3_MINI,
            1024,
            1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test XAI Agent Description';
    }
}
