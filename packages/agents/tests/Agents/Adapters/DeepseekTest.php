<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Deepseek;

class DeepseekTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Deepseek('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'deepseek';
    }

    protected function expectedDefaultModel(): string
    {
        return Deepseek::MODEL_DEEPSEEK_CHAT;
    }

    protected function expectedModels(): array
    {
        return [
            Deepseek::MODEL_DEEPSEEK_CHAT,
            Deepseek::MODEL_DEEPSEEK_CODER,
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
