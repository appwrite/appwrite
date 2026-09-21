<?php

namespace Utopia\Agents\Tests\Adapters;

use Utopia\Agents\Adapter as AgentAdapter;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Agent;
use Utopia\Agents\Message;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;

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

    public function testSchemaRequestIsPostedAsJsonWithBearerToken(): void
    {
        $client = new Client()->queue(200, json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => '{"ok":true}']]],
        ]) ?: '');
        $adapter = new OpenAI('secret', OpenAI::MODEL_GPT_4_1);
        $adapter->setClient($client);

        $object = new SchemaObject();
        $object->addProperty('ok', ['type' => SchemaObject::TYPE_BOOLEAN, 'description' => 'ok']);
        $agent = new Agent($adapter);
        $agent->setSchema(new Schema('Result', 'Result', $object, $object->getNames()));

        $message = $adapter->send([new Message('hi')]);

        $this->assertSame('{"ok":true}', $message->getContent());
        $this->assertCount(1, $client->requests);
        $request = $client->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer secret', $request->getHeaderLine('authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('content-type'));
        $payload = $client->lastPayload();
        $this->assertSame(OpenAI::MODEL_GPT_4_1, $payload['model']);
        $this->assertFalse($payload['stream']);
        $this->assertIsArray($payload['response_format']);
        $this->assertSame('json_schema', $payload['response_format']['type']);
    }

    public function testStreamedResponseIsAssembledAcrossChunkBoundaries(): void
    {
        $delta = static fn (string $token): string => 'data: '.json_encode(['choices' => [['delta' => ['content' => $token]]]])."\n";
        $first = $delta('Hel');
        $client = new Client()->queue(200, chunks: [
            substr($first, 0, 10),
            substr($first, 10).$delta('lo').'data: [DONE]',
        ]);
        $adapter = new OpenAI('secret');
        $adapter->setClient($client);
        new Agent($adapter);

        $tokens = [];
        $message = $adapter->send([new Message('hi')], function (string $token) use (&$tokens): void {
            $tokens[] = $token;
        });

        $this->assertSame('Hello', $message->getContent());
        $this->assertSame(['Hel', 'lo'], $tokens);
        $this->assertTrue($client->lastPayload()['stream']);
    }

    public function testEveryRequestGoesThroughTheSameClient(): void
    {
        $client = new Client()
            ->queue(200, chunks: ['data: '.json_encode(['choices' => [['delta' => ['content' => 'a']]]])."\n"])
            ->queue(200, chunks: ['data: '.json_encode(['choices' => [['delta' => ['content' => 'b']]]])."\n"]);
        $adapter = new OpenAI('secret');
        $adapter->setClient($client);
        new Agent($adapter);

        $adapter->send([new Message('one')]);
        $adapter->send([new Message('two')]);

        $this->assertCount(2, $client->requests);
        $this->assertSame($client, $adapter->getClient());
    }

    public function testErrorResponseThrowsWithStatusCode(): void
    {
        $client = new Client()->queue(401, json_encode(['error' => ['code' => 'invalid_api_key', 'message' => 'Bad key']]) ?: '');
        $adapter = new OpenAI('secret');
        $adapter->setClient($client);
        $object = new SchemaObject();
        $object->addProperty('ok', ['type' => SchemaObject::TYPE_BOOLEAN, 'description' => 'ok']);
        $agent = new Agent($adapter);
        $agent->setSchema(new Schema('Result', 'Result', $object, $object->getNames()));

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(401);
        $this->expectExceptionMessage('(invalid_api_key) Bad key');

        $adapter->send([new Message('hi')]);
    }
}
