<?php

namespace Utopia\Agents\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Event;
use Utopia\Agents\Events\ToolResult as ToolResultEvent;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Message;
use Utopia\Agents\Parts\Text;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Parts\ToolResult;
use Utopia\Agents\Response;
use Utopia\Agents\Role;
use Utopia\Agents\Schema;
use Utopia\Agents\Tests\Fakes\Model;
use Utopia\Agents\Tool;
use Utopia\Agents\Usage;

class ConversationTest extends TestCase
{
    public function testKeepsHistoryAcrossTurns(): void
    {
        $model = new Model([
            new Response([new Text('Hi Ada.')], FinishReason::Stop, new Usage(10, 2)),
            new Response([new Text('Your name is Ada.')], FinishReason::Stop, new Usage(20, 4)),
        ]);
        $conversation = new Conversation(new Agent($model, 'Be brief'));

        $conversation->send('I am Ada.');
        $result = $conversation->send('Who am I?');

        $this->assertSame('Your name is Ada.', $result->text);
        $this->assertSame(['I am Ada.', 'Hi Ada.', 'Who am I?'], array_map(fn (Message $message) => $message->text(), \array_slice($model->requests[1]->messages, 1)));
        $this->assertCount(4, $conversation->getMessages());
    }

    public function testUsageCountsEachRunOnce(): void
    {
        $model = new Model([
            new Response([new Text('a')], FinishReason::Stop, new Usage(10, 2)),
            new Response([new Text('b')], FinishReason::Stop, new Usage(20, 4)),
        ]);
        $conversation = new Conversation(new Agent($model));

        $conversation->send('one');
        $conversation->send('two');

        $this->assertSame(30, $conversation->getUsage()->input);
        $this->assertSame(6, $conversation->getUsage()->output);
    }

    public function testContinuesAfterClientToolResult(): void
    {
        $model = new Model([
            new Response([new ToolCall('call_1', 'confirm', [])], FinishReason::ToolCalls),
            new Response([new Text('Booked.')], FinishReason::Stop),
        ]);
        $agent = (new Agent($model))->addTool(new Tool('confirm', 'Ask the user', Schema::object([])));
        $conversation = new Conversation($agent);

        $pending = $conversation->send('Book it')->pending();
        $conversation->add(Message::tool([new ToolResult($pending[0]->id, $pending[0]->name, 'yes')]));
        $result = $conversation->send();

        $this->assertSame('Booked.', $result->text);
        $this->assertSame([Role::User, Role::Assistant, Role::Tool], array_map(fn (Message $message) => $message->role, $model->requests[1]->messages));
    }

    public function testKeepsFinishedStepsWhenRunFails(): void
    {
        $runs = 0;
        $model = new Model([new Response([new ToolCall('call_1', 'charge', [])], FinishReason::ToolCalls)]);
        $agent = (new Agent($model))->addTool(new Tool('charge', 'Charge the card', Schema::object([]), function () use (&$runs): string {
            $runs++;

            return 'charged';
        }));
        $conversation = new Conversation($agent);

        try {
            $conversation->send('Pay');
            $this->fail('Expected the second model call to fail');
        } catch (\LogicException) {
        }

        $this->assertSame(1, $runs);
        $this->assertSame([Role::User, Role::Assistant, Role::Tool], array_map(fn (Message $message) => $message->role, $conversation->getMessages()));
        $this->assertSame('charged', $conversation->getMessages()[2]->toolResults()[0]->output);
    }

    public function testKeepsStepWhenListenerFailsOnToolResult(): void
    {
        $runs = 0;
        $model = new Model([
            new Response([new ToolCall('call_1', 'charge', [])], FinishReason::ToolCalls),
            new Response([new Text('Paid.')], FinishReason::Stop),
        ]);
        $agent = (new Agent($model))->addTool(new Tool('charge', 'Charge the card', Schema::object([]), function () use (&$runs): string {
            $runs++;

            return 'charged';
        }));
        $conversation = new Conversation($agent);

        try {
            $conversation->send('Pay', function (Event $event): void {
                if ($event instanceof ToolResultEvent) {
                    throw new \RuntimeException('Listener failed');
                }
            });
            $this->fail('Expected the listener failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Listener failed', $error->getMessage());
        }

        $this->assertSame(1, $runs);
        $this->assertCount(1, $model->requests);
        $this->assertSame([Role::User, Role::Assistant, Role::Tool], array_map(fn (Message $message) => $message->role, $conversation->getMessages()));
    }

    public function testStreamsWithListener(): void
    {
        $model = new Model([new Response([new Text('streamed')], FinishReason::Stop)]);
        $conversation = new Conversation(new Agent($model));
        $events = 0;

        $conversation->send('go', function (Event $event) use (&$events): void {
            $events++;
        });

        $this->assertSame(3, $events);
    }
}
