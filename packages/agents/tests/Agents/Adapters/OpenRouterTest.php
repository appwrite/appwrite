<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\OpenRouter;
use Utopia\Agents\Adapters\OpenRouter\Models as OpenRouterModels;

class OpenRouterTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new OpenRouter('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'openrouter';
    }

    protected function expectedDefaultModel(): string
    {
        return OpenRouterModels::MODEL_OPENAI_GPT_4O;
    }

    protected function expectedModels(): array
    {
        return OpenRouterModels::MODELS;
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
        return 'https://openrouter.ai/api/v1/chat/completions';
    }

    public function testModelSetterAcceptsArbitraryRoutedModel(): void
    {
        $adapter = new OpenRouter('test-api-key');

        $adapter->setModel('meta-llama/llama-3.3-70b-instruct');

        $this->assertSame('meta-llama/llama-3.3-70b-instruct', $adapter->getModel());
    }

    public function testConstructorAcceptsCustomEndpoint(): void
    {
        $adapter = new OpenRouter(
            apiKey: 'test-api-key',
            endpoint: 'https://custom-proxy.example/v1/chat/completions'
        );

        $this->assertSame('https://custom-proxy.example/v1/chat/completions', $adapter->getEndpoint());
    }
}
