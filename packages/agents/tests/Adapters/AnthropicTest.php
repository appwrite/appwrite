<?php

namespace Utopia\Agents\Tests\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Agent;
use Utopia\Agents\Message;

class AnthropicTest extends Adapter
{
    protected function createAdapter(): AgentAdapter
    {
        return new Anthropic('test-api-key');
    }

    protected function expectedName(): string
    {
        return 'anthropic';
    }

    protected function expectedDefaultModel(): string
    {
        return Anthropic::MODEL_CLAUDE_3_HAIKU;
    }

    protected function expectedModels(): array
    {
        return [
            Anthropic::MODEL_CLAUDE_4_OPUS,
            Anthropic::MODEL_CLAUDE_3_OPUS,
            Anthropic::MODEL_CLAUDE_4_SONNET,
            Anthropic::MODEL_CLAUDE_3_7_SONNET,
            Anthropic::MODEL_CLAUDE_3_5_SONNET,
            Anthropic::MODEL_CLAUDE_3_5_HAIKU,
            Anthropic::MODEL_CLAUDE_3_HAIKU,
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

    public function testRequestCarriesApiKeyAndVersionHeaders(): void
    {
        $client = new Client()->queue(200, chunks: [
            'data: '.json_encode(['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'hi']])."\n",
        ]);
        $adapter = new Anthropic('secret');
        $adapter->setClient($client);
        new Agent($adapter);

        $message = $adapter->send([new Message('hi')]);

        $this->assertSame('hi', $message->getContent());
        $request = $client->lastRequest();
        $this->assertSame('https://api.anthropic.com/v1/messages', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));
        $this->assertSame('application/json', $request->getHeaderLine('content-type'));
    }
}
