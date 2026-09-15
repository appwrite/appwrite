<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Ollama;

class OllamaTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Ollama();
    }

    protected function expectedName(): string
    {
        return 'ollama';
    }

    protected function expectedDefaultModel(): string
    {
        return Ollama::MODEL_EMBEDDING_GEMMA;
    }

    protected function expectedModels(): array
    {
        return Ollama::MODELS;
    }

    protected function expectsSchemaSupport(): bool
    {
        return false;
    }

    protected function expectsEmbeddingSupport(): bool
    {
        return true;
    }

    protected function supportsEndpointMutator(): bool
    {
        return true;
    }

    protected function expectedDefaultEndpoint(): ?string
    {
        return 'http://ollama:11434/api/embed';
    }

    public function testInvalidModelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ollama('invalid-model-name');
    }

    public function testSendIsNotSupported(): void
    {
        $adapter = new Ollama();

        $this->expectException(\Exception::class);
        $adapter->send([]);
    }
}
