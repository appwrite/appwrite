<?php

namespace Utopia\Agents\Tests\E2E\Models;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Event;
use Utopia\Agents\Events\Finish;
use Utopia\Agents\Events\TextDelta;
use Utopia\Agents\FinishReason;
use Utopia\Agents\Model;
use Utopia\Agents\Parts\ToolCall;
use Utopia\Agents\Schema;
use Utopia\Agents\Tool;

/**
 * Runs the same scenarios against a real provider. Each subclass names the
 * API key variable; a test is skipped when the key is not set.
 */
abstract class Base extends TestCase
{
    abstract protected function model(string $key): Model;

    abstract protected function key(): string;

    protected function supportsTools(): bool
    {
        return true;
    }

    protected function agent(string $instructions = ''): Agent
    {
        $key = getenv($this->key());
        if (! \is_string($key) || $key === '') {
            $this->markTestSkipped($this->key().' is not set');
        }

        return (new Agent($this->model($key), $instructions))->setMaxTokens(1024);
    }

    public function testGenerate(): void
    {
        $result = $this->agent('Answer with a single word.')->generate('What is the capital of France?');

        $this->assertStringContainsString('paris', strtolower($result->text));
        $this->assertSame(FinishReason::Stop, $result->finish);
        $this->assertGreaterThan(0, $result->usage->input);
        $this->assertGreaterThan(0, $result->usage->output);
    }

    public function testStream(): void
    {
        $deltas = '';
        $finished = false;

        $result = $this->agent()->stream('Count from 1 to 5, separated by spaces.', function (Event $event) use (&$deltas, &$finished): void {
            if ($event instanceof TextDelta) {
                $deltas .= $event->delta;
            }
            if ($event instanceof Finish) {
                $finished = true;
            }
        });

        $this->assertTrue($finished);
        $this->assertSame($result->text, $deltas);
        $this->assertStringContainsString('5', $result->text);
    }

    public function testConversationRemembers(): void
    {
        $conversation = new Conversation($this->agent('Be brief.'));

        $conversation->send('My favorite color is teal. Just acknowledge.');
        $result = $conversation->send('What is my favorite color? Answer with one word.');

        $this->assertStringContainsString('teal', strtolower($result->text));
        $this->assertCount(4, $conversation->getMessages());
    }

    public function testTools(): void
    {
        if (! $this->supportsTools()) {
            $this->markTestSkipped('Provider does not support tools');
        }

        $calls = [];
        $weather = new Tool(
            'weather',
            'Get the current temperature in Celsius for a city',
            Schema::object(['city' => Schema::string('City name')]),
            function (array $input, ToolCall $call) use (&$calls): array {
                $calls[] = $input['city'];

                return ['temperature' => 23];
            },
        );

        $result = $this->agent()->addTool($weather)->generate('What is the temperature in Lisbon right now? Use the tool.');

        $this->assertCount(1, $calls);
        $this->assertStringContainsString('lisbon', strtolower(\is_string($calls[0]) ? $calls[0] : ''));
        $this->assertStringContainsString('23', $result->text);
        $this->assertGreaterThanOrEqual(2, \count($result->steps));
    }

    public function testStructuredOutput(): void
    {
        $schema = Schema::object([
            'name' => Schema::string(),
            'year' => Schema::integer(),
        ]);

        $result = $this->agent()->setSchema($schema)->generate('Return the name of the programming language created by Rasmus Lerdorf and the year it was released.');

        $this->assertIsArray($result->output);
        $this->assertSame('PHP', strtoupper(\is_string($result->output['name'] ?? null) ? $result->output['name'] : ''));
        $this->assertSame(1995, $result->output['year'] ?? null);
    }
}
