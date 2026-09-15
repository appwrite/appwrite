<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Gemini;

class GeminiTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Gemini('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'gemini';
    }

    protected function expectedDefaultModel(): string
    {
        return Gemini::MODEL_GEMINI_2_0_FLASH;
    }

    protected function expectedModels(): array
    {
        return [
            Gemini::MODEL_GEMINI_2_0_FLASH,
            Gemini::MODEL_GEMINI_2_0_FLASH_LITE,
            Gemini::MODEL_GEMINI_1_5_PRO,
            Gemini::MODEL_GEMINI_2_5_FLASH_PREVIEW,
            Gemini::MODEL_GEMINI_2_5_PRO_PREVIEW,
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
        return 'https://generativelanguage.googleapis.com/v1beta/models/'
            .Gemini::MODEL_GEMINI_2_0_FLASH
            .':streamGenerateContent?alt=sse&key=test-api-key';
    }
}
