<?php

namespace Utopia\Tests\Agents\Conversation;

use Utopia\Agents\Adapter;
use Utopia\Agents\Adapters\Gemini;

class ConversationGeminiTest extends ConversationBase
{
    protected function createAdapter(): Adapter
    {
        $apiKey = getenv('LLM_KEY_GEMINI');

        if ($apiKey === false || empty($apiKey)) {
            throw new \RuntimeException('LLM_KEY_GEMINI environment variable is not set');
        }

        return new Gemini(
            $apiKey,
            Gemini::MODEL_GEMINI_2_0_FLASH_LITE,
            1024,
            1.0
        );
    }

    protected function getAgentDescription(): string
    {
        return 'Test Gemini Agent Description';
    }
}
