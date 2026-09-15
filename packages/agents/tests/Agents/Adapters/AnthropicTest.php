<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Anthropic;

class AnthropicTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Anthropic('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'anthropic';
    }

    protected function expectedDefaultModel(): string
    {
        return Anthropic::MODEL_CLAUDE_3_HAIKU;
    }

    protected function expectedModels(): array
    {
        return [
            Anthropic::MODEL_CLAUDE_4_OPUS,
            Anthropic::MODEL_CLAUDE_3_OPUS,
            Anthropic::MODEL_CLAUDE_4_SONNET,
            Anthropic::MODEL_CLAUDE_3_7_SONNET,
            Anthropic::MODEL_CLAUDE_3_5_SONNET,
            Anthropic::MODEL_CLAUDE_3_5_HAIKU,
            Anthropic::MODEL_CLAUDE_3_HAIKU,
        ];
    }

    protected function expectsSchemaSupport(): bool
    {
        return true;
    }

    protected function expectsEmbeddingSupport(): bool
    {
        return false;
    }
}
