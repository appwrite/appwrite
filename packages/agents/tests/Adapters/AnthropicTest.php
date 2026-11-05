<?php

namespace Utopia\Agents\Tests\Adapters;

use PHPUnit\Framework\Attributes\DataProvider;
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
    private function adapter(Client $client, string $model = 'claude-test'): Anthropic
    {
        return (new Anthropic('secret', $model, client: $client))->setRetries(1, 0);
    }

    /**
     * @param  list<array{0: array<string, mixed>, 1: list<array<string, mixed>>}>  $blocks  Each: [start block, list of deltas]
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
        $chunks = array_map(fn (array $event) => 'event: '.(\is_string($event['type'] ?? null) ? $event['type'] : '')."\ndata: ".json_encode($event)."\n\n", $events);

        return (new Client())->queue(200, chunks: $chunks);
    }

    public function testPayloadMapsSystemToolsAndMergesTurns(): void
    {
        $client = $this->client($this->message([[['type' => 'text', 'text' => ''], [['type' => 'text_delta', 'text' => 'ok']]]], 'end_turn'));
        $tool = new Tool('weather', 'Weather for a city', Schema::object(['city' => Schema::string()]));

        $this->adapter($client, 'claude-sonnet-4-6')->generate(new Request(
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
        $this->assertSame('claude-sonnet-4-6', $payload['model']);
        $this->assertSame(4096, $payload['max_tokens']);
        $this->assertSame(0.3, $payload['temperature']);
        $this->assertSame(5, $payload['top_k']);
        $this->assertSame(['END'], $payload['stop_sequences']);
        $this->assertSame(['user_id' => 'u1'], $payload['metadata']);
        $this->assertSame([['type' => 'text', 'text' => 'Be brief', 'cache_control' => ['type' => 'ephemeral']]], $payload['system']);
        $this->assertSame(['type' => 'any'], $payload['tool_choice']);
        $this->assertNull($client->payload('tools', 0, 'cache_control'));
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

    public function testEffortUsesAdaptiveThinkingOnCurrentModels(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, 'claude-opus-5-5')->generate(new Request([Message::user('Hi')], maxTokens: 1000, effort: Effort::Medium));

        $payload = $client->lastPayload();
        $this->assertSame(['type' => 'adaptive'], $payload['thinking']);
        $this->assertSame(['effort' => 'medium'], $payload['output_config']);
        $this->assertSame(1000, $payload['max_tokens']);
    }

    public function testXHighEffortIsHighBeforeClaude47(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, 'claude-opus-4-6')->generate(new Request([Message::user('Hi')], effort: Effort::XHigh));

        $this->assertSame('high', $client->payload('output_config', 'effort'));
    }

    public function testXHighEffortIsKeptFromClaude47(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, 'claude-opus-4-7')->generate(new Request([Message::user('Hi')], effort: Effort::XHigh));

        $this->assertSame('xhigh', $client->payload('output_config', 'effort'));
    }

    public function testEffortIsAThinkingBudgetBeforeClaude46(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, 'claude-haiku-4-5-20251001')->generate(new Request([Message::user('Hi')], maxTokens: 1000, effort: Effort::Medium));

        $payload = $client->lastPayload();
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 8192], $payload['thinking']);
        $this->assertSame(8192 + 4096, $payload['max_tokens']);
        $this->assertNull($client->payload('output_config'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function claude4Models(): array
    {
        return [
            'claude 4.8' => ['claude-opus-4-8'],
            'two-digit minor' => ['claude-opus-4-10'],
        ];
    }

    #[DataProvider('claude4Models')]
    public function testNoEffortLeavesThinkingOffBeforeClaude5(string $model): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, $model)->generate(new Request([Message::user('Hi')], effort: Effort::None));

        $this->assertNull($client->payload('thinking'));
        $this->assertNull($client->payload('output_config'));
    }

    public function testNoEffortAsksForTheLowestEffortFromClaude5(): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, 'claude-haiku-5-5')->generate(new Request([Message::user('Hi')], effort: Effort::None));

        $this->assertNull($client->payload('thinking'));
        $this->assertSame(['effort' => 'low'], $client->payload('output_config'));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function samplingModels(): array
    {
        return [
            'claude 3.5' => ['claude-3-5-haiku-latest', true],
            'claude 4 dated' => ['claude-sonnet-4-20250514', true],
            'claude 4.6' => ['claude-sonnet-4-6', true],
            'claude 4.7' => ['claude-opus-4-7', false],
            'claude 5.5' => ['claude-sonnet-5-5', false],
            'fable' => ['claude-fable-5-1', false],
            'no version' => ['claude-test', false],
        ];
    }

    #[DataProvider('samplingModels')]
    public function testSamplingIsSentOnlyToModelsThatAcceptIt(string $model, bool $sent): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, $model)->generate(new Request([Message::user('Hi')], temperature: 0.0, topP: 0.9, topK: 5));

        $payload = $client->lastPayload();
        $this->assertSame($sent, \array_key_exists('temperature', $payload));
        $this->assertSame($sent, \array_key_exists('top_p', $payload));
        $this->assertSame($sent, \array_key_exists('top_k', $payload));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function thinkingModels(): array
    {
        return [
            'budget thinking' => ['claude-haiku-4-5-20251001'],
            'adaptive thinking' => ['claude-sonnet-4-6'],
        ];
    }

    #[DataProvider('thinkingModels')]
    public function testSamplingIsDroppedWhileThinking(string $model): void
    {
        $client = $this->client($this->message([], 'end_turn'));

        $this->adapter($client, $model)->generate(new Request([Message::user('Hi')], temperature: 0.3, topP: 0.9, topK: 5, stop: ['END'], effort: Effort::Medium));

        $payload = $client->lastPayload();
        $this->assertArrayHasKey('thinking', $payload);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertArrayNotHasKey('top_p', $payload);
        $this->assertArrayNotHasKey('top_k', $payload);
        $this->assertSame(['END'], $payload['stop_sequences']);
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

    public function testStructuredOutputUsesTheJsonSchemaFormat(): void
    {
        $client = $this->client($this->message([
            [['type' => 'text', 'text' => ''], [['type' => 'text_delta', 'text' => '{"name":"Ada"}']]],
        ], 'end_turn'));
        $schema = Schema::object(['name' => Schema::string()]);
        $tool = new Tool('weather', 'Weather for a city', Schema::object(['city' => Schema::string()]));

        $response = $this->adapter($client, 'claude-opus-5-5')->generate(new Request([Message::user('Hi')], tools: [$tool], schema: $schema, effort: Effort::None));

        $payload = $client->lastPayload();
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema->toArray()], 'effort' => 'low'], $payload['output_config']);
        $this->assertArrayNotHasKey('tool_choice', $payload, 'current models reject a forced tool');
        $this->assertIsArray($payload['tools']);
        $this->assertSame(['weather'], array_column($payload['tools'], 'name'));
        $this->assertSame('{"name":"Ada"}', $response->text());
    }

    public function testStructuredOutputKeepsTheEffort(): void
    {
        $client = $this->client($this->message([], 'end_turn'));
        $schema = Schema::object(['name' => Schema::string()]);

        $this->adapter($client, 'claude-opus-5-5')->generate(new Request([Message::user('Hi')], schema: $schema, effort: Effort::High));

        $payload = $client->lastPayload();
        $this->assertSame(['type' => 'adaptive'], $payload['thinking']);
        $this->assertSame(['format' => ['type' => 'json_schema', 'schema' => $schema->toArray()], 'effort' => 'high'], $payload['output_config']);
    }

    public function testStructuredOutputWithoutEffortForcesTheJsonToolBeforeClaude46(): void
    {
        $client = $this->client($this->message([], 'end_turn'));
        $schema = Schema::object(['name' => Schema::string()]);

        $this->adapter($client, 'claude-3-7-sonnet-latest')->generate(new Request([Message::user('Hi')], schema: $schema, effort: Effort::None));

        $payload = $client->lastPayload();
        $this->assertArrayNotHasKey('thinking', $payload);
        $this->assertArrayNotHasKey('output_config', $payload);
        $this->assertSame(['type' => 'tool', 'name' => 'json'], $payload['tool_choice']);
    }

    public function testStructuredOutputUsesForcedJsonToolBeforeClaude45(): void
    {
        $client = $this->client($this->message([
            [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'json', 'input' => []], [
                ['type' => 'input_json_delta', 'partial_json' => '{"name":'],
                ['type' => 'input_json_delta', 'partial_json' => '"Ada"}'],
            ]],
        ], 'tool_use'));
        $schema = Schema::object(['name' => Schema::string()]);
        $events = [];

        $response = $this->adapter($client, 'claude-3-5-haiku-latest')->stream(new Request([Message::user('Hi')], schema: $schema), function (Event $event) use (&$events): void {
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
