<?php

namespace Utopia\Tests\Agents\Adapters;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Agent as RuntimeAgent;

abstract class Adapter extends TestCase
{
    abstract protected function createAdapter(): AgentAdapter;

    abstract protected function expectedName(): string;

    abstract protected function expectedDefaultModel(): string;

    /**
     * @return array<string>
     */
    abstract protected function expectedModels(): array;

    abstract protected function expectsSchemaSupport(): bool;

    abstract protected function expectsEmbeddingSupport(): bool;

    protected function supportsEndpointMutator(): bool
    {
        return false;
    }

    protected function expectedDefaultEndpoint(): ?string
    {
        return null;
    }

    public function testAdapterIdentityAndDefaultModel(): void
    {
        $adapter = $this->createAdapter();

        $this->assertSame($this->expectedName(), $adapter->getName());
        $this->assertSame($this->expectedDefaultModel(), $adapter->getModel());
        $this->assertContains($adapter->getModel(), $adapter->getModels());
    }

    public function testModelsContract(): void
    {
        $adapter = $this->createAdapter();
        $models = $adapter->getModels();

        $this->assertSame($this->expectedModels(), $models);
        $this->assertNotEmpty($models);
    }

    public function testSetModelRoundTripWithKnownModel(): void
    {
        $adapter = $this->createAdapter();
        $models = $adapter->getModels();
        $targetModel = $models[0];

        $result = $adapter->setModel($targetModel);

        $this->assertSame($adapter, $result);
        $this->assertSame($targetModel, $adapter->getModel());
    }

    public function testSchemaSupportFlag(): void
    {
        $adapter = $this->createAdapter();

        $this->assertSame($this->expectsSchemaSupport(), $adapter->isSchemaSupported());
    }

    public function testEmbeddingSupportFlag(): void
    {
        $adapter = $this->createAdapter();

        $this->assertSame($this->expectsEmbeddingSupport(), $adapter->getSupportForEmbeddings());
    }

    public function testTimeoutRoundTrip(): void
    {
        $adapter = $this->createAdapter();
        $result = $adapter->setTimeout(12345);

        $this->assertSame($adapter, $result);
        $this->assertSame(12345, $adapter->getTimeout());
    }

    public function testTokenCountersAccumulateAndTotalIsConsistent(): void
    {
        $adapter = $this->createAdapter();

        $adapter
            ->countInputTokens(3)
            ->countOutputTokens(5)
            ->countCacheCreationInputTokens(7)
            ->countCacheReadInputTokens(11);

        $this->assertSame(3, $adapter->getInputTokens());
        $this->assertSame(5, $adapter->getOutputTokens());
        $this->assertSame(7, $adapter->getCacheCreationInputTokens());
        $this->assertSame(11, $adapter->getCacheReadInputTokens());
        $this->assertSame(26, $adapter->getTotalTokens());
    }

    public function testAgentReferenceRoundTrip(): void
    {
        $adapter = $this->createAdapter();
        $this->assertNull($adapter->getAgent());

        $agent = new RuntimeAgent($adapter);

        $this->assertSame($agent, $adapter->getAgent());
    }

    public function testEndpointRoundTripWhenSupported(): void
    {
        if (! $this->supportsEndpointMutator()) {
            $this->markTestSkipped('Adapter does not expose endpoint mutators');
        }

        $adapter = $this->createAdapter();
        if (! method_exists($adapter, 'getEndpoint') || ! method_exists($adapter, 'setEndpoint')) {
            $this->fail('supportsEndpointMutator() is true but get/set endpoint methods are missing');
        }

        $expectedDefault = $this->expectedDefaultEndpoint();
        if ($expectedDefault !== null) {
            /** @var callable $getEndpoint */
            $getEndpoint = [$adapter, 'getEndpoint'];
            $this->assertSame($expectedDefault, $getEndpoint());
        }

        /** @var callable $setEndpoint */
        $setEndpoint = [$adapter, 'setEndpoint'];
        /** @var callable $getEndpoint */
        $getEndpoint = [$adapter, 'getEndpoint'];
        $result = $setEndpoint('https://example.local/endpoint');

        $this->assertSame($adapter, $result);
        $this->assertSame('https://example.local/endpoint', $getEndpoint());
    }

    public function testEmbedBehaviorMatchesEmbeddingSupport(): void
    {
        $adapter = $this->createAdapter();

        if ($this->expectsEmbeddingSupport()) {
            $this->assertGreaterThan(0, $adapter->getEmbeddingDimension());

            return;
        }

        $this->expectException(\Exception::class);
        $adapter->embed('test');
    }

    public function testGetEmbeddingDimensionWhenNotSupportedThrows(): void
    {
        if ($this->expectsEmbeddingSupport()) {
            $this->markTestSkipped('Adapter supports embeddings');
        }

        $adapter = $this->createAdapter();

        $this->expectException(\Exception::class);
        $adapter->getEmbeddingDimension();
    }
}
