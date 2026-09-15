<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Perplexity;

class PerplexityTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Perplexity('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'perplexity';
    }

    protected function expectedDefaultModel(): string
    {
        return Perplexity::MODEL_SONAR;
    }

    protected function expectedModels(): array
    {
        return [
            Perplexity::MODEL_SONAR,
            Perplexity::MODEL_SONAR_PRO,
            Perplexity::MODEL_SONAR_DEEP_RESEARCH,
            Perplexity::MODEL_SONAR_REASONING,
            Perplexity::MODEL_SONAR_REASONING_PRO,
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
        return 'https://api.perplexity.ai/chat/completions';
    }
}
