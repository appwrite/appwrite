<?php

namespace Utopia\Agents\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Effort;
use Utopia\Agents\Event;
use Utopia\Agents\Events\ReasoningDelta;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
use Utopia\Agents\Events\ToolInputDelta;
use Utopia\Agents\Exception\Api;
use Utopia\Agents\Exception\Authentication;
use Utopia\Agents\Exception\RateLimit;
use Utopia\Agents\Exception\Unsupported;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Message;
use Utopia\Agents\Parts\File;
use Utopia\Agents\Parts\Image;
use Utopia\Agents\Parts\Reasoning;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;
use Utopia\Agents\Request;
use Utopia\Agents\Schema;
use Utopia\Agents\Tests\Fakes\Client;
use Utopia\Agents\Tool;
use Utopia\Agents\ToolChoice;

class OpenAITest extends TestCase
{
    private function adapter(Client $client): OpenAI
    {
        return (new OpenAI('secret', 'gpt-test', client: $client))->setRetries(2, 0);
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>
     */
    private function chunk(array $delta, ?string $finish = null): array
    {
        return ['id' => 'chatcmpl-1', 'model' => 'gpt-test-2025', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]];
    }

    public function testPayloadMapsMessagesToolsAndSettings(): void
    {
        $client = (new Client())->events([$this->chunk(['content' => 'ok'], 'stop')]);
        $tool = new Tool('weather', 'Weather for a city', Schema::object(['city' => Schema::string()]));

        $this->adapter($client)->generate(new Request(
            messages: [
                Message::system('Be brief'),
                Message::user([new Text('Look'), new Image('png-bytes', 'image/png'), new File('pdf-bytes', 'application/pdf', 'a.pdf')]),
                Message::assistant([new Reasoning('hmm'), new Text('Checking'), new ToolCall('call_1', 'weather', ['city' => 'Rome'])]),
                Message::tool([new ToolResult('call_1', 'weather', ['temp' => 20])]),
            ],
            tools: [$tool],
            toolChoice: ToolChoice::tool('weather'),
            maxTokens: 50,
            temperature: 0.5,
            stop: ['END'],
            seed: 3,
            effort: Effort::High,
            options: ['openai' => ['user' => 'u1'], 'anthropic' => ['ignored' => true]],
        ));

        $request = $client->lastRequest();
        $this->assertSame('https://api.openai.com/v1/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer secret', $request->getHeaderLine('authorization'));

        $payload = $client->lastPayload();
        $this->assertSame('gpt-test', $payload['model']);
        $this->assertTrue($payload['stream']);
        $this->assertSame(['include_usage' => true], $payload['stream_options']);
        $this->assertSame(50, $payload['max_completion_tokens']);
        $this->assertSame(0.5, $payload['temperature']);
        $this->assertSame(['END'], $payload['stop']);
        $this->assertSame(3, $payload['seed']);
        $this->assertSame('high', $payload['reasoning_effort']);
        $this->assertSame('u1', $payload['user']);
        $this->assertArrayNotHasKey('ignored', $payload);
        $this->assertArrayNotHasKey('top_p', $payload);
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'weather']], $payload['tool_choice']);
        $this->assertSame([
            'type' => 'function',
            'function' => [
                'name' => 'weather',
                'description' => 'Weather for a city',
                'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city'], 'additionalProperties' => false],
            ],
        ], $client->payload('tools', 0));

        $this->assertSame([
            ['role' => 'system', 'content' => 'Be brief'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Look'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('png-bytes')]],
                ['type' => 'file', 'file' => ['filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode('pdf-bytes')]],
            ]],
            ['role' => 'assistant', 'content' => 'Checking', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{"city":"Rome"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"temp":20}'],
        ], $payload['messages']);
    }

    public function testPayloadRequestsStrictJsonSchema(): void
    {
        $client = (new Client())->events([$this->chunk(['content' => '{}'], 'stop')]);
        $schema = Schema::object(['name' => Schema::string()]);

        $this->adapter($client)->generate(new Request([Message::user('Hi')], schema: $schema));

        $this->assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'response', 'strict' => true, 'schema' => $schema->toArray()],
        ], $client->payload('response_format'));
        $this->assertSame('Hi', $client->payload('messages', 0, 'content'));
    }

    public function testStreamsTextReasoningAndUsage(): void
    {
        $client = (new Client())->events([
            $this->chunk(['role' => 'assistant', 'reasoning_content' => 'Think']),
            $this->chunk(['content' => 'Hel']),
            $this->chunk(['content' => 'lo']),
            $this->chunk([], 'stop'),
            ['id' => 'chatcmpl-1', 'choices' => [], 'usage' => [
                'prompt_tokens' => 12,
                'completion_tokens' => 7,
                'prompt_tokens_details' => ['cached_tokens' => 4],
                'completion_tokens_details' => ['reasoning_tokens' => 3],
            ]],
        ]);
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')]), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertEquals([new ReasoningDelta('Think'), new TextDelta('Hel'), new TextDelta('lo')], $events);
        $this->assertEquals([new Reasoning('Think'), new Text('Hello')], $response->parts);
        $this->assertSame(FinishReason::Stop, $response->finish);
        $this->assertSame('chatcmpl-1', $response->id);
        $this->assertSame('gpt-test-2025', $response->model);
        $this->assertSame(12, $response->usage->input);
        $this->assertSame(7, $response->usage->output);
        $this->assertSame(4, $response->usage->cacheRead);
        $this->assertSame(3, $response->usage->reasoning);
    }

    public function testAssemblesToolCallsFromDeltas(): void
    {
        $client = (new Client())->events([
            $this->chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '']]]]),
            $this->chunk(['tool_calls' => [['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'time', 'arguments' => '{}']]]]),
            $this->chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"city":']]]]),
            $this->chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"Rome"}']]]]),
            $this->chunk([], 'tool_calls'),
        ]);
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')]), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertEquals([new ToolCall('call_a', 'weather', ['city' => 'Rome']), new ToolCall('call_b', 'time', [])], $response->parts);
        $this->assertSame(FinishReason::ToolCalls, $response->finish);
        $this->assertEquals(new ToolInputDelta('call_a', 'weather', '{"city":'), $events[1]);
        $this->assertEquals(new ToolCallEvent(new ToolCall('call_a', 'weather', ['city' => 'Rome'])), $events[3]);
    }

    public function testParsesEventsSplitAcrossChunksAndCrlf(): void
    {
        $stream = 'data: '.json_encode($this->chunk(['content' => 'Hi there'], 'stop'))."\r\n\r\ndata: [DONE]\r\n\r\n";
        $client = (new Client())->queue(200, chunks: str_split($stream, 7));

        $response = $this->adapter($client)->generate(new Request([Message::user('Hi')]));

        $this->assertSame('Hi there', $response->text());
    }

    public function testRetriesRetryableErrors(): void
    {
        $client = (new Client())
            ->queue(429, '{"error":{"message":"Slow down","type":"rate_limit"}}')
            ->queue(503, 'upstream unavailable')
            ->events([$this->chunk(['content' => 'ok'], 'stop')]);

        $response = $this->adapter($client)->generate(new Request([Message::user('Hi')]));

        $this->assertSame('ok', $response->text());
        $this->assertCount(3, $client->requests);
    }

    public function testThrowsRateLimitAfterRetries(): void
    {
        $client = (new Client())
            ->queue(429, '{"error":{"message":"Slow down","code":"rate_limit_exceeded"}}', headers: ['retry-after' => '0'])
            ->queue(429, '{"error":{"message":"Slow down","code":"rate_limit_exceeded"}}', headers: ['retry-after' => '0'])
            ->queue(429, '{"error":{"message":"Slow down","code":"rate_limit_exceeded"}}', headers: ['retry-after' => '0']);

        try {
            $this->adapter($client)->generate(new Request([Message::user('Hi')]));
            $this->fail('Expected a rate limit exception');
        } catch (RateLimit $error) {
            $this->assertSame(429, $error->status);
            $this->assertSame('rate_limit_exceeded', $error->type);
            $this->assertSame(0.0, $error->retryAfter);
            $this->assertTrue($error->retryable);
            $this->assertSame('Openai API error (429, rate_limit_exceeded): Slow down', $error->getMessage());
        }
        $this->assertCount(3, $client->requests);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $client = (new Client())->queue(401, '{"error":{"message":"Bad key","code":"invalid_api_key"}}');

        $this->expectException(Authentication::class);

        try {
            $this->adapter($client)->generate(new Request([Message::user('Hi')]));
        } finally {
            $this->assertCount(1, $client->requests);
        }
    }

    public function testMidStreamErrorThrows(): void
    {
        $client = (new Client())->events([
            $this->chunk(['content' => 'partial']),
            ['error' => ['message' => 'Provider crashed', 'code' => 502]],
        ]);

        $this->expectException(Api::class);
        $this->expectExceptionMessage('Provider crashed');

        $this->adapter($client)->generate(new Request([Message::user('Hi')]));
    }

    public function testRejectsUnsupportedUserContent(): void
    {
        $this->expectException(Unsupported::class);

        $this->adapter(new Client())->generate(new Request([Message::user([new Text('a'), new File(url: 'https://x.io/a.pdf')])]));
    }

    public function testCustomEndpoint(): void
    {
        $client = (new Client())->events([$this->chunk(['content' => 'ok'], 'stop')]);

        (new OpenAI('key', 'local', 'http://localhost:8080/v1/chat/completions', client: $client))->generate(new Request([Message::user('Hi')]));

        $this->assertSame('http://localhost:8080/v1/chat/completions', (string) $client->lastRequest()->getUri());
    }
}
