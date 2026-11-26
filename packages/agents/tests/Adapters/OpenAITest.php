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

/**
 * OpenAI over the Responses API.
 */
class OpenAITest extends TestCase
{
    private function adapter(Client $client, string $model = 'gpt-test'): OpenAI
    {
        return (new OpenAI('secret', $model, client: $client))->setRetries(2, 0);
    }

    /**
     * The events of a response that answers with one message.
     *
     * @return list<array<string, mixed>>
     */
    private function answer(string $text): array
    {
        $message = ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]];

        return [
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'gpt-test-2026', 'status' => 'in_progress']],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'delta' => $text],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $message],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'model' => 'gpt-test-2026', 'status' => 'completed', 'output' => [$message]]],
        ];
    }

    public function testPayloadMapsMessagesToolsAndSettings(): void
    {
        $client = (new Client())->events($this->answer('ok'));
        $tool = new Tool('weather', 'Weather for a city', Schema::object(['city' => Schema::string()]));

        $this->adapter($client)->generate(new Request(
            messages: [
                Message::system('Be brief'),
                Message::user([new Text('Look'), new Image('png-bytes', 'image/png'), new File('pdf-bytes', 'application/pdf', 'a.pdf')]),
                Message::assistant([
                    new Reasoning('', 'encrypted-1', id: 'rs_1'),
                    new Reasoning('unsigned thoughts'),
                    new Text('Checking'),
                    new ToolCall('call_1', 'weather', ['city' => 'Rome']),
                ]),
                Message::tool([new ToolResult('call_1', 'weather', ['temp' => 20])]),
            ],
            tools: [$tool],
            toolChoice: ToolChoice::tool('weather'),
            maxTokens: 50,
            temperature: 0.5,
            seed: 3,
            effort: Effort::High,
            options: ['openai' => ['service_tier' => 'flex'], 'anthropic' => ['ignored' => true]],
        ));

        $request = $client->lastRequest();
        $this->assertSame('https://api.openai.com/v1/responses', (string) $request->getUri());
        $this->assertSame('Bearer secret', $request->getHeaderLine('authorization'));

        $payload = $client->lastPayload();
        $this->assertSame('gpt-test', $payload['model']);
        $this->assertTrue($payload['stream']);
        $this->assertFalse($payload['store']);
        $this->assertSame(50, $payload['max_output_tokens']);
        $this->assertSame(0.5, $payload['temperature']);
        $this->assertSame(['effort' => 'high'], $payload['reasoning']);
        $this->assertSame(['reasoning.encrypted_content'], $payload['include']);
        $this->assertSame('flex', $payload['service_tier']);
        $this->assertArrayNotHasKey('ignored', $payload);
        $this->assertArrayNotHasKey('seed', $payload);
        $this->assertArrayNotHasKey('top_p', $payload);
        $this->assertSame(['type' => 'function', 'name' => 'weather'], $payload['tool_choice']);
        $this->assertSame([
            'type' => 'function',
            'name' => 'weather',
            'description' => 'Weather for a city',
            'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city'], 'additionalProperties' => false],
            'strict' => false,
        ], $client->payload('tools', 0));

        $this->assertSame([
            ['role' => 'system', 'content' => 'Be brief'],
            ['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => 'Look'],
                ['type' => 'input_image', 'image_url' => 'data:image/png;base64,'.base64_encode('png-bytes')],
                ['type' => 'input_file', 'filename' => 'a.pdf', 'file_data' => 'data:application/pdf;base64,'.base64_encode('pdf-bytes')],
            ]],
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'encrypted-1'],
            ['role' => 'assistant', 'content' => 'Checking'],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'weather', 'arguments' => '{"city":"Rome"}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"temp":20}'],
        ], $payload['input']);
    }

    public function testPayloadRequestsStrictJsonSchema(): void
    {
        $client = (new Client())->events($this->answer('{}'));
        $schema = Schema::object(['name' => Schema::string()]);

        $this->adapter($client)->generate(new Request([Message::user('Hi')], schema: $schema));

        $this->assertSame([
            'format' => ['type' => 'json_schema', 'name' => 'response', 'strict' => true, 'schema' => $schema->toArray()],
        ], $client->payload('text'));
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $client->payload('input'));
        $this->assertNull($client->payload('reasoning'));
        $this->assertNull($client->payload('include'));
    }

    public function testStreamsTextReasoningAndUsage(): void
    {
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Think']], 'encrypted_content' => 'sealed'];
        $message = ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hello']]];
        $client = (new Client())->events([
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'gpt-test-2026', 'status' => 'in_progress']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
            ['type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs_1', 'output_index' => 0, 'summary_index' => 0, 'delta' => 'Think'],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $reasoning],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 1, 'delta' => 'Hel'],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 1, 'delta' => 'lo'],
            ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => $message],
            ['type' => 'response.completed', 'response' => [
                'id' => 'resp_1',
                'model' => 'gpt-test-2026',
                'status' => 'completed',
                'output' => [$reasoning, $message],
                'usage' => [
                    'input_tokens' => 12,
                    'input_tokens_details' => ['cached_tokens' => 4],
                    'output_tokens' => 7,
                    'output_tokens_details' => ['reasoning_tokens' => 3],
                ],
            ]],
        ]);
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')]), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertEquals([new ReasoningDelta('Think'), new TextDelta('Hel'), new TextDelta('lo')], $events);
        $this->assertEquals([new Reasoning('Think', 'sealed', id: 'rs_1'), new Text('Hello')], $response->parts);
        $this->assertSame(FinishReason::Stop, $response->finish);
        $this->assertSame('resp_1', $response->id);
        $this->assertSame('gpt-test-2026', $response->model);
        $this->assertSame(12, $response->usage->input);
        $this->assertSame(7, $response->usage->output);
        $this->assertSame(4, $response->usage->cacheRead);
        $this->assertSame(3, $response->usage->reasoning);
    }

    public function testAssemblesToolCallsFromItems(): void
    {
        $weather = ['type' => 'function_call', 'id' => 'fc_a', 'call_id' => 'call_a', 'name' => 'weather', 'arguments' => '{"city":"Rome"}'];
        $time = ['type' => 'function_call', 'id' => 'fc_b', 'call_id' => 'call_b', 'name' => 'time', 'arguments' => '{}'];
        $client = (new Client())->events([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_a', 'call_id' => 'call_a', 'name' => 'weather', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_a', 'output_index' => 0, 'delta' => '{"city":'],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_a', 'output_index' => 0, 'delta' => '"Rome"}'],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $weather],
            ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'fc_b', 'call_id' => 'call_b', 'name' => 'time', 'arguments' => '']],
            ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => $time],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'output' => [$weather, $time]]],
        ]);
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')]), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertEquals([new ToolCall('call_a', 'weather', ['city' => 'Rome']), new ToolCall('call_b', 'time', [])], $response->parts);
        $this->assertSame(FinishReason::ToolCalls, $response->finish);
        $this->assertEquals(new ToolInputDelta('call_a', 'weather', '{"city":'), $events[0]);
        $this->assertEquals(new ToolCallEvent(new ToolCall('call_a', 'weather', ['city' => 'Rome'])), $events[2]);
        $this->assertEquals(new ToolCallEvent(new ToolCall('call_b', 'time', [])), $events[3]);
    }

    public function testFillsItemsFromTheFinalResponse(): void
    {
        $message = ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'late']]];
        $client = (new Client())->events([
            ['type' => 'response.completed', 'response' => ['id' => 'resp_1', 'status' => 'completed', 'output' => [$message]]],
        ]);

        $response = $this->adapter($client)->generate(new Request([Message::user('Hi')]));

        $this->assertSame('late', $response->text());
        $this->assertSame(FinishReason::Stop, $response->finish);
    }

    public function testIncompleteResponseReportsItsReason(): void
    {
        $client = (new Client())->events([
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'delta' => 'cut'],
            ['type' => 'response.incomplete', 'response' => [
                'id' => 'resp_1',
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'cut']]]],
            ]],
        ]);

        $response = $this->adapter($client)->generate(new Request([Message::user('Hi')]));

        $this->assertSame('cut', $response->text());
        $this->assertSame(FinishReason::Length, $response->finish);
    }

    public function testErrorEventThrows(): void
    {
        $client = (new Client())->events([
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'delta' => 'partial'],
            ['type' => 'error', 'code' => 'server_error', 'message' => 'Provider crashed', 'param' => null],
        ]);

        try {
            $this->adapter($client)->generate(new Request([Message::user('Hi')]));
            $this->fail('Expected an API exception');
        } catch (Api $error) {
            $this->assertSame('server_error', $error->type);
            $this->assertSame('Openai API error (server_error): Provider crashed', $error->getMessage());
        }
        $this->assertCount(1, $client->requests);
    }

    public function testFailedResponseThrows(): void
    {
        $client = (new Client())->events([
            ['type' => 'response.failed', 'response' => ['id' => 'resp_1', 'status' => 'failed', 'error' => ['code' => 'invalid_prompt', 'message' => 'Bad prompt']]],
        ]);

        $this->expectException(Api::class);
        $this->expectExceptionMessage('Bad prompt');

        $this->adapter($client)->generate(new Request([Message::user('Hi')]));
    }

    public function testRetriesRetryableErrors(): void
    {
        $client = (new Client())
            ->queue(429, '{"error":{"message":"Slow down","type":"rate_limit"}}')
            ->events($this->answer('ok'));

        $response = $this->adapter($client)->generate(new Request([Message::user('Hi')]));

        $this->assertSame('ok', $response->text());
        $this->assertCount(2, $client->requests);
    }

    public function testRejectsStopSequences(): void
    {
        $this->expectException(Unsupported::class);

        $this->adapter(new Client())->generate(new Request([Message::user('Hi')], stop: ['END']));
    }

    public function testSendsFileUrls(): void
    {
        $client = (new Client())->events($this->answer('ok'));

        $this->adapter($client)->generate(new Request([Message::user([new Text('Read'), new File(url: 'https://x.io/a.pdf')])]));

        $this->assertSame(['type' => 'input_file', 'file_url' => 'https://x.io/a.pdf'], $client->payload('input', 0, 'content', 1));
    }

    public function testGpt6ReasonsWithToolsAndDropsSamplingUnlessReasoningIsOff(): void
    {
        $client = (new Client())
            ->events($this->answer('a'))
            ->events($this->answer('b'));
        $adapter = $this->adapter($client, 'gpt-6.1-sol');
        $tool = new Tool('read', 'Read a file', Schema::object(['path' => Schema::string()]));

        $adapter->generate(new Request([Message::user('Hi')], tools: [$tool], temperature: 0.2, topP: 0.5, effort: Effort::Medium));
        $this->assertNull($client->payload('temperature'));
        $this->assertNull($client->payload('top_p'));
        $this->assertSame(['effort' => 'medium'], $client->payload('reasoning'));
        $this->assertSame(['reasoning.encrypted_content'], $client->payload('include'));
        $this->assertSame('read', $client->payload('tools', 0, 'name'));

        $adapter->generate(new Request([Message::user('Hi')], temperature: 0.2, effort: Effort::None));
        $this->assertSame(0.2, $client->payload('temperature'));
        $this->assertSame(['effort' => 'none'], $client->payload('reasoning'));
        $this->assertNull($client->payload('include'));
    }

    public function testRejectsUnsupportedUserContent(): void
    {
        $this->expectException(Unsupported::class);

        $this->adapter(new Client())->generate(new Request([Message::user([new Text('a'), new ToolCall('call_1', 'x')])]));
    }

    public function testCustomEndpoint(): void
    {
        $client = (new Client())->events($this->answer('ok'));

        (new OpenAI('key', 'gpt-test', 'https://proxy.example.com/v1/responses', client: $client))->generate(new Request([Message::user('Hi')]));

        $this->assertSame('https://proxy.example.com/v1/responses', (string) $client->lastRequest()->getUri());
    }
}
