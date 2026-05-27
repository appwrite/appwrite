<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\AppwriteEmbeddingAdapter;

class AppwriteEmbeddingAdapterTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new AppwriteEmbeddingAdapter();
    }

    protected function expectedName(): string
    {
        return 'appwrite-embedding';
    }

    protected function expectedDefaultModel(): string
    {
        return AppwriteEmbeddingAdapter::MODEL_NOMIC_EMBED_TEXT;
    }

    protected function expectedModels(): array
    {
        return AppwriteEmbeddingAdapter::MODELS;
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
        return 'http://appwrite-embedding:11434/embed';
    }

    public function testInvalidModelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AppwriteEmbeddingAdapter('invalid-model-name');
    }

    public function testSendIsNotSupported(): void
    {
        $adapter = new AppwriteEmbeddingAdapter();

        $this->expectException(\Exception::class);
        $adapter->send([]);
    }
}
