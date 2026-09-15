<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\XAI;

class XAITest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new XAI('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'xai';
    }

    protected function expectedDefaultModel(): string
    {
        return XAI::MODEL_GROK_3_MINI;
    }

    protected function expectedModels(): array
    {
        return [
            XAI::MODEL_GROK_3,
            XAI::MODEL_GROK_3_MINI,
            XAI::MODEL_GROK_2_IMAGE,
        ];
    }

    protected function expectsSchemaSupport(): bool
    {
        return false;
    }

    protected function expectsEmbeddingSupport(): bool
    {
        return false;
    }

    protected function supportsEndpointMutator(): bool
    {
        return true;
    }

    protected function expectedDefaultEndpoint(): ?string
    {
        return 'https://api.x.ai/v1/chat/completions';
    }
}
