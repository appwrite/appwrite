<?php

namespace Utopia\Agents\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapters\Gemini;
use Utopia\Agents\Effort;
use Utopia\Agents\Event;
use Utopia\Agents\Events\ReasoningDelta;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Message;
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

class GeminiTest extends TestCase
{
    private function adapter(Client $client): Gemini
    {
        return (new Gemini('secret', 'gemini-test', client: $client))->setRetries(0);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function chunk(array $parts, ?string $finish = null): array
    {
        $candidate = ['content' => ['role' => 'model', 'parts' => $parts]];
        if ($finish !== null) {
            $candidate['finishReason'] = $finish;
        }

        return ['candidates' => [$candidate], 'responseId' => 'resp_1', 'modelVersion' => 'gemini-test-001'];
    }

    public function testPayloadMapsContentsToolsAndConfig(): void
    {
        $client = (new Client())->events([$this->chunk([['text' => 'ok']], 'STOP')], "\r\n\r\n");
        $tool = new Tool('weather', 'Weather for a city', Schema::object(['city' => Schema::string()]));

        $this->adapter($client)->generate(new Request(
            messages: [
                Message::system('Be brief'),
                Message::user([new Text('Look'), new Image('png', 'image/png'), new Image(url: 'gs://bucket/a.png')]),
                Message::assistant([new Reasoning('hmm', 'sig'), new ToolCall('call_0', 'weather', ['city' => 'Rome'], 'sig-1')]),
                Message::tool([new ToolResult('call_0', 'weather', ['temp' => 20])]),
            ],
            tools: [$tool],
            toolChoice: ToolChoice::tool('weather'),
            schema: Schema::object(['answer' => Schema::string()]),
            maxTokens: 64,
            topP: 0.8,
            seed: 9,
            effort: Effort::Low,
        ));

        $request = $client->lastRequest();
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-test:streamGenerateContent?alt=sse', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('x-goog-api-key'));

        $payload = $client->lastPayload();
        $this->assertSame(['parts' => [['text' => 'Be brief']]], $payload['systemInstruction']);
        $this->assertSame([
            ['role' => 'user', 'parts' => [
                ['text' => 'Look'],
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('png')]],
                ['fileData' => ['mimeType' => 'image/png', 'fileUri' => 'gs://bucket/a.png']],
            ]],
            ['role' => 'model', 'parts' => [
                ['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Rome']], 'thoughtSignature' => 'sig-1'],
            ]],
            ['role' => 'user', 'parts' => [
                ['functionResponse' => ['name' => 'weather', 'response' => ['output' => ['temp' => 20]]]],
            ]],
        ], $payload['contents']);
        $this->assertSame('weather', $client->payload('tools', 0, 'functionDeclarations', 0, 'name'));
        $this->assertSame($tool->input->toArray(), $client->payload('tools', 0, 'functionDeclarations', 0, 'parametersJsonSchema'));
        $this->assertSame(['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => ['weather']]], $payload['toolConfig']);
        $this->assertSame(64, $client->payload('generationConfig', 'maxOutputTokens'));
        $this->assertSame(0.8, $client->payload('generationConfig', 'topP'));
        $this->assertSame(9, $client->payload('generationConfig', 'seed'));
        $this->assertSame('application/json', $client->payload('generationConfig', 'responseMimeType'));
        $this->assertSame(['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer'], 'additionalProperties' => false], $client->payload('generationConfig', 'responseJsonSchema'));
        $this->assertSame(['thinkingBudget' => 1024, 'includeThoughts' => true], $client->payload('generationConfig', 'thinkingConfig'));
    }

    public function testThinkingBudgetStaysWithinModelLimit(): void
    {
        $client = (new Client())
            ->events([$this->chunk([['text' => 'ok']], 'STOP')])
            ->events([$this->chunk([['text' => 'ok']], 'STOP')])
            ->events([$this->chunk([['text' => 'ok']], 'STOP')]);

        (new Gemini('k', 'gemini-2.5-flash', client: $client))->generate(new Request([Message::user('Hi')], effort: Effort::Max));
        $this->assertSame(['thinkingBudget' => 24576, 'includeThoughts' => true], $client->payload('generationConfig', 'thinkingConfig'));

        (new Gemini('k', 'gemini-2.5-pro', client: $client))->generate(new Request([Message::user('Hi')], effort: Effort::Max));
        $this->assertSame(['thinkingBudget' => 32768, 'includeThoughts' => true], $client->payload('generationConfig', 'thinkingConfig'));

        (new Gemini('k', 'gemini-2.5-flash', client: $client))->generate(new Request([Message::user('Hi')], effort: Effort::None));
        $this->assertSame(['thinkingBudget' => 0, 'includeThoughts' => false], $client->payload('generationConfig', 'thinkingConfig'));
    }

    public function testStreamsThoughtsTextAndFunctionCalls(): void
    {
        $client = (new Client())->events([
            $this->chunk([['text' => 'Plan', 'thought' => true]]),
            $this->chunk([['text' => 'Checking']]),
            $this->chunk([['functionCall' => ['name' => 'weather', 'args' => ['city' => 'Rome']], 'thoughtSignature' => 'sig-1']], 'STOP')
                + ['usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5, 'cachedContentTokenCount' => 8]],
        ], "\r\n\r\n");
        $events = [];

        $response = $this->adapter($client)->stream(new Request([Message::user('Hi')]), function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $call = new ToolCall('call_0', 'weather', ['city' => 'Rome'], 'sig-1');
        $this->assertEquals([new ReasoningDelta('Plan'), new TextDelta('Checking'), new ToolCallEvent($call)], $events);
        $this->assertEquals([new Reasoning('Plan'), new Text('Checking'), $call], $response->parts);
        $this->assertSame(FinishReason::ToolCalls, $response->finish);
        $this->assertSame('resp_1', $response->id);
        $this->assertSame('gemini-test-001', $response->model);
        $this->assertSame(30, $response->usage->input);
        $this->assertSame(15, $response->usage->output);
        $this->assertSame(5, $response->usage->reasoning);
        $this->assertSame(8, $response->usage->cacheRead);
    }

    public function testMapsFinishReasons(): void
    {
        $client = (new Client())
            ->events([$this->chunk([['text' => 'Hi']], 'MAX_TOKENS')])
            ->events([$this->chunk([], 'SAFETY')])
            ->events([$this->chunk([['text' => 'Done']], 'STOP')]);
        $adapter = $this->adapter($client);
        $request = new Request([Message::user('Hi')]);

        $this->assertSame(FinishReason::Length, $adapter->generate($request)->finish);
        $this->assertSame(FinishReason::ContentFilter, $adapter->generate($request)->finish);
        $this->assertSame(FinishReason::Stop, $adapter->generate($request)->finish);
    }

    public function testToolErrorResultIsSentAsError(): void
    {
        $client = (new Client())->events([$this->chunk([['text' => 'ok']], 'STOP')]);

        $this->adapter($client)->generate(new Request([
            Message::user('Go'),
            Message::assistant([new ToolCall('call_0', 'weather', [])]),
            Message::tool([new ToolResult('call_0', 'weather', 'Service down', true)]),
        ]));

        $this->assertSame(['functionResponse' => ['name' => 'weather', 'response' => ['error' => 'Service down']]], $client->payload('contents', 2, 'parts', 0));
        $this->assertStringContainsString('"args":{}', (string) $client->lastRequest()->getBody());
    }
}
