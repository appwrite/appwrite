<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\Deepseek;

class ConversationDeepseekTest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_DEEPSEEK');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_DEEPSEEK environment variable is not set');
        }

        return new Deepseek(
            $apiKey,
            Deepseek::MODEL_DEEPSEEK_CHAT,
            1024,
            1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test Deepseek Agent Description';
    }
}
