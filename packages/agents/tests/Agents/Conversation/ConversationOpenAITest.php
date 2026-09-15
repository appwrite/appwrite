<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\OpenAI;

class ConversationOpenAITest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_OPENAI');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_OPENAI environment variable is not set');
        }

        return new OpenAI(
            $apiKey,
            OpenAI::MODEL_O3_MINI,
            1024,
            1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test OpenAI Agent Description';
    }
}
