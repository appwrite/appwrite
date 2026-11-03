<?php

namespace Utopia\Agents\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Agent;
use Utopia\Agents\Effort;
use Utopia\Agents\Event;
use Utopia\Agents\Events\Finish;
use Utopia\Agents\Events\StepFinish;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\Events\ToolCall as ToolCallEvent;
use Utopia\Agents\Events\ToolResult as ToolResultEvent;
use Utopia\Agents\Exception\Output;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Message;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;
use Utopia\Agents\Response;
use Utopia\Agents\Role;
use Utopia\Agents\Schema;
use Utopia\Agents\Step;
use Utopia\Agents\Tests\Fakes\Model;
use Utopia\Agents\Tool;
use Utopia\Agents\ToolChoice;
use Utopia\Agents\Usage;

class AgentTest extends TestCase
{
    private function text(string $text, int $input = 1, int $output = 1): Response
    {
        return new Response([new Text($text)], FinishReason::Stop, new Usage($input, $output));
    }

    /**
     * @param  list<ToolCall>  $calls
     */
    private function calls(array $calls): Response
    {
        return new Response($calls, FinishReason::ToolCalls, new Usage(1, 1));
    }

    private function weather(): Tool
    {
        return new Tool(
            'weather',
            'Current weather for a city',
            Schema::object(['city' => Schema::string()]),
            fn (array $input) => ['city' => $input['city'], 'temperature' => 21],
        );
    }

    public function testGenerateSendsInstructionsSettingsAndPrompt(): void
    {
        $model = new Model([$this->text('Hello!')]);
        $agent = (new Agent($model, 'Be friendly'))
            ->setMaxTokens(100)
            ->setTemperature(0.2)
            ->setTopP(0.9)
            ->setTopK(40)
            ->setStop(['END'])
            ->setSeed(7)
            ->setEffort(Effort::Low)
            ->setToolChoice(ToolChoice::none())
            ->setOptions(['fake' => ['user' => 'u1']]);

        $result = $agent->generate('Hi');

        $request = $model->requests[0];
        $this->assertSame('Be friendly', $request->system());
        $this->assertSame(Role::User, $request->messages[1]->role);
        $this->assertSame('Hi', $request->messages[1]->text());
        $this->assertSame(100, $request->maxTokens);
        $this->assertSame(0.2, $request->temperature);
        $this->assertSame(0.9, $request->topP);
        $this->assertSame(40, $request->topK);
        $this->assertSame(['END'], $request->stop);
        $this->assertSame(7, $request->seed);
        $this->assertSame(Effort::Low, $request->effort);
        $this->assertSame(ToolChoice::NONE, $request->toolChoice?->type);
        $this->assertSame(['user' => 'u1'], $request->options('fake'));

        $this->assertSame('Hello!', $result->text);
        $this->assertSame(FinishReason::Stop, $result->finish);
        $this->assertCount(1, $result->steps);
        $this->assertCount(1, $result->messages);
        $this->assertSame(Role::Assistant, $result->messages[0]->role);
    }

    public function testRunsToolsUntilModelAnswers(): void
    {
        $model = new Model([
            $this->calls([new ToolCall('call_1', 'weather', ['city' => 'Berlin'])]),
            $this->text('It is 21 degrees in Berlin.'),
        ]);
        $agent = (new Agent($model))->addTool($this->weather());

        $result = $agent->generate('Weather in Berlin?');

        $this->assertSame('It is 21 degrees in Berlin.', $result->text);
        $this->assertCount(2, $result->steps);
        $this->assertCount(2, $model->requests);
        $this->assertSame('weather', $model->requests[0]->tools[0]->name);

        $second = $model->requests[1]->messages;
        $this->assertSame(Role::Assistant, $second[1]->role);
        $this->assertSame(Role::Tool, $second[2]->role);
        $this->assertSame(['city' => 'Berlin', 'temperature' => 21], $second[2]->toolResults()[0]->output);

        $this->assertCount(1, $result->toolCalls());
        $this->assertCount(1, $result->toolResults());
        $this->assertFalse($result->toolResults()[0]->error);
        $this->assertSame([Role::Assistant, Role::Tool, Role::Assistant], array_map(fn (Message $message) => $message->role, $result->messages));
        $this->assertSame(4, $result->usage->total());
    }

    public function testToolFailuresBecomeErrorResults(): void
    {
        $model = new Model([
            $this->calls([
                new ToolCall('call_1', 'missing', []),
                new ToolCall('call_2', 'weather', ['city' => 5]),
                new ToolCall('call_3', 'broken', []),
            ]),
            $this->text('Sorry.'),
        ]);
        $broken = new Tool('broken', 'Always fails', Schema::object([]), function (): never {
            throw new \RuntimeException('Service down');
        });
        $agent = (new Agent($model))->setTools([$this->weather(), $broken]);

        $results = $agent->generate('Go')->toolResults();

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result->error);
        }
        $this->assertSame("Unknown tool 'missing'. Available tools: weather, broken", $results[0]->output);
        $this->assertSame('Invalid arguments: $.city: must be of type string', $results[1]->output);
        $this->assertSame('Service down', $results[2]->output);
    }

    public function testStopsAtStepLimit(): void
    {
        $call = $this->calls([new ToolCall('call_1', 'weather', ['city' => 'Rome'])]);
        $model = new Model([$call, $call, $call]);
        $agent = (new Agent($model))->addTool($this->weather())->setSteps(2);

        $result = $agent->generate('Loop');

        $this->assertCount(2, $result->steps);
        $this->assertSame(FinishReason::ToolCalls, $result->finish);
    }

    public function testStopsWhenConditionHolds(): void
    {
        $call = $this->calls([new ToolCall('call_1', 'weather', ['city' => 'Rome'])]);
        $model = new Model([$call, $call]);
        $agent = (new Agent($model))
            ->addTool($this->weather())
            ->setUntil(fn (array $steps) => \count($steps) === 1);

        $this->assertCount(1, $agent->generate('Once')->steps);
    }

    public function testClientSideToolLeavesCallPending(): void
    {
        $model = new Model([
            $this->calls([new ToolCall('call_1', 'weather', ['city' => 'Oslo']), new ToolCall('call_2', 'confirm', [])]),
        ]);
        $confirm = new Tool('confirm', 'Ask the user to confirm', Schema::object([]));
        $agent = (new Agent($model))->setTools([$this->weather(), $confirm]);

        $result = $agent->generate('Book it');

        $this->assertCount(1, $model->requests);
        $this->assertCount(1, $result->toolResults());
        $this->assertSame('call_2', $result->pending()[0]->id);
    }

    public function testStructuredOutputIsDecodedAndValidated(): void
    {
        $schema = Schema::object(['name' => Schema::string(), 'age' => Schema::integer()]);
        $model = new Model([$this->text("```json\n{\"name\":\"Ada\",\"age\":36}\n```")]);
        $agent = (new Agent($model))->setSchema($schema);

        $result = $agent->generate('Who?');

        $this->assertSame($schema, $model->requests[0]->schema);
        $this->assertSame(['name' => 'Ada', 'age' => 36], $result->output);
    }

    public function testStructuredOutputAcceptsSingleLineFence(): void
    {
        $schema = Schema::object(['ok' => Schema::boolean()]);

        foreach (['```{"ok":true}```', '```json {"ok":true}```', "```JSON\n{\"ok\":true}\n```"] as $text) {
            $model = new Model([$this->text($text)]);

            $this->assertSame(['ok' => true], (new Agent($model))->setSchema($schema)->generate('Ok?')->output, $text);
        }
    }

    public function testInvalidStructuredOutputThrows(): void
    {
        $model = new Model([$this->text('{"name":"Ada"}')]);
        $agent = (new Agent($model))->setSchema(Schema::object(['name' => Schema::string(), 'age' => Schema::integer()]));

        try {
            $agent->generate('Who?');
            $this->fail('Expected an output exception');
        } catch (Output $error) {
            $this->assertSame(['$.age: is required'], $error->errors);
            $this->assertSame('{"name":"Ada"}', $error->text);
        }
    }

    public function testNonJsonStructuredOutputThrows(): void
    {
        $model = new Model([$this->text('not json')]);
        $agent = (new Agent($model))->setSchema(Schema::object([]));

        $this->expectException(Output::class);

        $agent->generate('Who?');
    }

    public function testStreamEmitsEventsInOrder(): void
    {
        $model = new Model([
            $this->calls([new ToolCall('call_1', 'weather', ['city' => 'Paris'])]),
            $this->text('Sunny.'),
        ]);
        $agent = (new Agent($model))->addTool($this->weather());
        $events = [];

        $result = $agent->stream('Weather?', function (Event $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertSame([
            ToolCallEvent::class,
            ToolResultEvent::class,
            StepFinish::class,
            TextDelta::class,
            StepFinish::class,
            Finish::class,
        ], array_map(fn (Event $event) => $event::class, $events));
        $this->assertInstanceOf(Finish::class, $events[5]);
        $this->assertSame($result, $events[5]->result);
        $this->assertInstanceOf(StepFinish::class, $events[2]);
        $this->assertInstanceOf(Step::class, $events[2]->step);
    }

    public function testAcceptsMessageHistory(): void
    {
        $model = new Model([$this->text('Ada.')]);

        (new Agent($model))->generate([
            Message::user('My name is Ada.'),
            Message::assistant('Nice to meet you.'),
            Message::user('What is my name?'),
        ]);

        $this->assertCount(3, $model->requests[0]->messages);
        $this->assertSame('', $model->requests[0]->system());
    }

    public function testToolHandlerReceivesCall(): void
    {
        $seen = null;
        $tool = new Tool('echo', 'Echo', Schema::object(['value' => Schema::string()]), function (array $input, ToolCall $call) use (&$seen): string {
            $seen = $call->id;

            return \is_string($input['value']) ? $input['value'] : '';
        });
        $model = new Model([$this->calls([new ToolCall('call_9', 'echo', ['value' => 'hi'])]), $this->text('done')]);

        $result = (new Agent($model))->addTool($tool)->generate('Echo');

        $this->assertSame('call_9', $seen);
        $this->assertEquals(new ToolResult('call_9', 'echo', 'hi'), $result->toolResults()[0]);
    }

    public function testRejectsZeroSteps(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Agent(new Model()))->setSteps(0);
    }
}
