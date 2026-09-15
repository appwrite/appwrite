<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\OpenAI;

class OpenAITest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new OpenAI('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'openai';
    }

    protected function expectedDefaultModel(): string
    {
        return OpenAI::MODEL_O3_MINI;
    }

    protected function expectedModels(): array
    {
        return [
            OpenAI::MODEL_GPT_5_NANO,
            OpenAI::MODEL_GPT_4_5_PREVIEW,
            OpenAI::MODEL_GPT_4_1,
            OpenAI::MODEL_GPT_4O,
            OpenAI::MODEL_O4_MINI,
            OpenAI::MODEL_O3,
            OpenAI::MODEL_O3_MINI,
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

    protected function supportsEndpointMutator(): bool
    {
        return true;
    }

    protected function expectedDefaultEndpoint(): ?string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }
}
