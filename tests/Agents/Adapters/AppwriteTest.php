<?php

namespace Utopia\Tests\Agents\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Appwrite;

class AppwriteTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Appwrite();
    }

    protected function expectedName(): string
    {
        return 'appwrite-embedding';
    }

    protected function expectedDefaultModel(): string
    {
        return Appwrite::MODEL_NOMIC_EMBED_TEXT;
    }

    protected function expectedModels(): array
    {
        return Appwrite::MODELS;
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

        new Appwrite('invalid-model-name');
    }

    public function testSendIsNotSupported(): void
    {
        $adapter = new Appwrite();

        $this->expectException(\Exception::class);
        $adapter->send([]);
    }

    public function testEmbedReturnsVector(): void
    {
        $adapter = new Appwrite();

        $result = $adapter->embed('hello world');

        $this->assertCount($adapter->getEmbeddingDimension(), $result['embedding']);
        $this->assertNotEmpty($result['embedding']);
    }

    public function testBulkEmbedReturnsVectorPerInput(): void
    {
        $adapter = new Appwrite();
        $texts = ['hello world', 'goodbye world', 'embedding service test'];

        $result = $adapter->bulkEmbed($texts);

        $this->assertCount(count($texts), $result['embeddings']);
        foreach ($result['embeddings'] as $vec) {
            $this->assertCount($adapter->getEmbeddingDimension(), $vec);
            $this->assertNotEmpty($vec);
        }
    }

    public function testBulkEmbedPreservesOrder(): void
    {
        $adapter = new Appwrite();

        $singleA = $adapter->embed('alpha sentence')['embedding'];
        $singleB = $adapter->embed('beta sentence')['embedding'];
        $batch = $adapter->bulkEmbed(['alpha sentence', 'beta sentence'])['embeddings'];

        $this->assertEquals($singleA, $batch[0]);
        $this->assertEquals($singleB, $batch[1]);
    }
}
