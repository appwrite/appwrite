<?php

namespace Utopia\Agents\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Effort;
use Utopia\Agents\Event;
use Utopia\Agents\Events\ReasoningDelta;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
use Utopia\Agents\Events\ToolInputDelta;
use Utopia\Agents\Exception\Api;
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

class AnthropicTest extends TestCase
{
    private function adapter(Client $client): Anthropic
    {
        return (new Anthropic('secret', 'claude-test', client: $client))->setRetries(1, 0);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks  Each: [start block, list of deltas]
     * @return list<array<string, mixed>>
     */
    private function message(array $blocks, string $stop): array
    {
        $events = [['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-test-1', 'usage' => [
            'input_tokens' => 10, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 5, 'output_tokens' => 1,
        ]]]];

        foreach ($blocks as $index => [$start, $deltas]) {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $start];
            foreach ($deltas as $delta) {
                $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $index];
        }

        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop], 'usage' => ['output_tokens' => 42]];
        $events[] = ['type' => 'message_stop'];

        return $events;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function client(array $events): Client
    {
        $chunks = array_map(fn (array $event) => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events);

        return (new Client())->queue(200, chunks: $chunks);
    }

    public function testPayloadMapsSystemToolsAndMergesTurns(): void
    {
        $client = $this->client($this->message([[['type' => 'text', 'text' => ''], [['type' => 'text_delta', 'text' => 'ok']]]], 'end_turn'));
        $tool = new Tool('weather', 'Weather for a city', Schema::object(['city' => Schema::string()]));

        $this->adapter($client)->generate(new Request(
            messages: [
                Message::system('Be brief'),
                Message::user([new Text('Look'), new Image('png', 'image/png'), new File(url: 'https://x.io/a.pdf')]),
                Message::assistant([new Reasoning('hmm', 'sig'), new Reasoning('unsigned'), new ToolCall('toolu_1', 'weather', [])]),
                Message::tool([new ToolResult('toolu_1', 'weather', 'sunny', true)]),
                Message::user('Thanks'),
            ],
            tools: [$tool],
            toolChoice: ToolChoice::required(),
            temperature: 0.3,
            topK: 5,
            stop: ['END'],
            options: ['anthropic' => ['metadata' => ['user_id' => 'u1']]],
        ));

        $request = $client->lastRequest();
        $this->assertSame('secret', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));

        $payload = $client->lastPayload();
        $this->assertSame('claude-test', $payload['model']);
        $this->assertSame(4096, $payload['max_tokens']);
        $this->assertSame(0.3, $payload['temperature']);
        $this->assertSame(5, $payload['top_k']);
        $this->assertSame(['END'], $payload['stop_sequences']);
        $this->assertSame(['user_id' => 'u1'], $payload['metadata']);
        $this->assertSame([['type' => 'text', 'text' => 'Be brief', 'cache_control' => ['type' => 'ephemeral']]], $payload['system']);
        $this->assertSame(['type' => 'any'], $payload['tool_choice']);
        $this->assertArrayNotHasKey('cache_control', $client->payload('tools', 0));
        $this->assertSame('weather', $client->payload('tools', 0, 'name'));

        $this->assertSame([
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Look'],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('png')]],
                ['type' => 'document', 'source' => ['type' => 'url', 'url' => 'https://x.io/a.pdf']],
            ]],
            ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'sig'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => []],
            ]],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'sunny', 'is_error' => true],
                ['type' => 'text', 'text' => 'Thanks'],
            ]],
        ], $payload['messages']);
        $this->assertStringContainsString('"input":{}', (string) $request->getBody());
    }

    public function testEffortEnablesThinkingWithRoomForAnswer(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client)->generate(new Request([Message::user('Hi')], maxTokens: 1000, effort: Effort::Medium));

        $payload = $client->lastPayload();
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 8192], $payload['thinking']);
        $this->assertSame(8192 + 4096, $payload['max_tokens']);
    }

    public function testNoEffortLeavesThinkingOff(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client)->generate(new Request([Message::user('Hi')], schema: Schema::object([]), effort: Effort::None));

        $this->assertNull($client->payload('thinking'));
        $this->assertSame(['type' => 'tool', 'name' => 'json'], $client->payload('tool_choice'));
    }

    public function testStreamsThinkingTextAndToolUse(): void
    {
        $client = $this->client($this->message([
            [['type' => 'thinking', 'thinking' => ''], [['type' => 'thinking_delta', 'thinking' => 'Plan'], ['type' => 'signature_delta', 'signature' => 'abc']]],
            [['type' => 'text', 'text' => ''], [['type' => 'text_delta', 'text' => 'Let me check.']]],
            [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => []], [
                ['type' => 'input_json_delta', 'partial_json' => '{"city":'],
                ['type' => 'input_json_delta', 'partial_json' => '"Rome"}'],
            ]],
        ], 'tool_use'));
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')]), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $call = new ToolCall('toolu_1', 'weather', ['city' => 'Rome']);
        $this->assertEquals([
            new ReasoningDelta('Plan'),
            new TextDelta('Let me check.'),
            new ToolInputDelta('toolu_1', 'weather', '{"city":'),
            new ToolInputDelta('toolu_1', 'weather', '"Rome"}'),
            new ToolCallEvent($call),
        ], $events);
        $this->assertEquals([new Reasoning('Plan', 'abc'), new Text('Let me check.'), $call], $response->parts);
        $this->assertSame(FinishReason::ToolCalls, $response->finish);
        $this->assertSame('msg_1', $response->id);
        $this->assertSame('claude-test-1', $response->model);
        $this->assertSame(115, $response->usage->input);
        $this->assertSame(100, $response->usage->cacheRead);
        $this->assertSame(5, $response->usage->cacheWrite);
        $this->assertSame(42, $response->usage->output);
    }

    public function testStructuredOutputUsesForcedJsonTool(): void
    {
        $client = $this->client($this->message([
            [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'json', 'input' => []], [
                ['type' => 'input_json_delta', 'partial_json' => '{"name":'],
                ['type' => 'input_json_delta', 'partial_json' => '"Ada"}'],
            ]],
        ], 'tool_use'));
        $schema = Schema::object(['name' => Schema::string()]);
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')], schema: $schema), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $payload = $client->lastPayload();
        $this->assertSame(['type' => 'tool', 'name' => 'json'], $payload['tool_choice']);
        $this->assertSame('json', $client->payload('tools', 0, 'name'));
        $this->assertSame(['type' => 'ephemeral'], $client->payload('tools', 0, 'cache_control'));
        $this->assertSame($schema->toArray(), $client->payload('tools', 0, 'input_schema'));
        $this->assertEquals([new TextDelta('{"name":'), new TextDelta('"Ada"}')], $events);
        $this->assertSame('{"name":"Ada"}', $response->text());
        $this->assertSame([], $response->toolCalls());
        $this->assertSame(FinishReason::Stop, $response->finish);
    }

    public function testMapsStopReasons(): void
    {
        $client = $this->client($this->message([[['type' => 'text', 'text' => 'cut'], []]], 'max_tokens'));

        $this->assertSame(FinishReason::Length, $this->adapter($client)->generate(new Request([Message::user('Hi')]))->finish);
    }

    public function testStreamErrorEventThrows(): void
    {
        $client = $this->client([['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]);

        try {
            $this->adapter($client)->generate(new Request([Message::user('Hi')]));
            $this->fail('Expected an API exception');
        } catch (Api $error) {
            $this->assertSame('overloaded_error', $error->type);
            $this->assertTrue($error->retryable);
            $this->assertSame('Anthropic API error (overloaded_error): Overloaded', $error->getMessage());
        }
    }

    public function testHttpErrorIsRetriedThenThrown(): void
    {
        $client = (new Client())
            ->queue(529, '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}')
            ->queue(400, '{"type":"error","error":{"type":"invalid_request_error","message":"Bad"}}');

        try {
            $this->adapter($client)->generate(new Request([Message::user('Hi')]));
            $this->fail('Expected an API exception');
        } catch (Api $error) {
            $this->assertSame(400, $error->status);
            $this->assertSame('invalid_request_error', $error->type);
            $this->assertFalse($error->retryable);
        }
        $this->assertCount(2, $client->requests);
    }
}
