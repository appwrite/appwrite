<?php

namespace Utopia\Tests\Agents\Conversation;

use PHPUnit\Framework\TestCase;
use Utopia\Agents\Adapter;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Role;
use Utopia\Agents\Roles\Assistant;
use Utopia\Agents\Roles\User;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;

abstract class ConversationBase extends TestCase
{
    protected Conversation $conversation;

    protected Agent $agent;

    protected Adapter $adapter;

    /**
     * Abstract method to be implemented by child classes
     * to specify the specific Adapter
     */
    abstract protected function createAdapter(): Adapter;

    /**
     * Optional method to customize agent description
     */
    protected function getAgentDescription(): string
    {
        return 'Test Agent Description';
    }

    protected function setUp(): void
    {
        $this->adapter = $this->createAdapter();

        $this->agent = new Agent($this->adapter);
        $this->agent->setDescription($this->getAgentDescription());
        $this->agent->setInstructions([
            'description' => 'You are a helpful assistant that can answer questions and help with tasks.',
        ]);

        $this->conversation = new Conversation($this->agent);
    }

    public function testConstructor(): void
    {
        $this->assertSame($this->agent, $this->conversation->getAgent());
        $this->assertEmpty($this->conversation->getMessages());
        $this->assertSame(0, $this->conversation->getInputTokens());
        $this->assertSame(0, $this->conversation->getOutputTokens());
        $this->assertSame(0, $this->conversation->getTotalTokens());
    }

    public function testMessage(): void
    {
        $user = new User('user-1', 'Test User');
        $message = new Message('Hello, AI!');

        $result = $this->conversation->message($user, $message);

        $this->assertSame($this->conversation, $result);
        $this->assertCount(1, $this->conversation->getMessages());

        $firstMessage = $this->conversation->getMessages()[0];
        $this->assertSame(Role::ROLE_USER, $firstMessage->getRole());
        $this->assertSame('Hello, AI!', $firstMessage->getContent());
    }

    public function testMultipleMessages(): void
    {
        $user = new User('user-1', 'Test User');
        $assistant = new Assistant('assistant-1', 'Test Assistant');

        $this->conversation
            ->message($user, new Message('Hello'))
            ->message($assistant, new Message('Hi there!'))
            ->message($user, new Message('How are you?'));

        $messages = $this->conversation->getMessages();
        $this->assertCount(3, $messages);

        $this->assertSame(Role::ROLE_USER, $messages[0]->getRole());
        $this->assertSame('Hello', $messages[0]->getContent());

        $this->assertSame(Role::ROLE_ASSISTANT, $messages[1]->getRole());
        $this->assertSame('Hi there!', $messages[1]->getContent());

        $this->assertSame(Role::ROLE_USER, $messages[2]->getRole());
        $this->assertSame('How are you?', $messages[2]->getContent());
    }

    public function testSend(): void
    {
        $messages = $this->conversation
            ->message(new User('user-1', 'Test User'), new Message('Hello'))
            ->send();

        $this->assertInstanceOf(Message::class, $messages);

        // Verify the response was added to conversation
        $conversationMessages = $this->conversation->getMessages();
        $this->assertNotEmpty($conversationMessages);
        $this->assertSame(Role::ROLE_USER, $conversationMessages[0]->getRole());
        $this->assertSame('Hello', $conversationMessages[0]->getContent());

        // Verify AI response
        $this->assertSame(Role::ROLE_ASSISTANT, $conversationMessages[1]->getRole());
        $this->assertNotEmpty($conversationMessages[1]->getContent());
    }

    public function testSchema(): void
    {
        if (! $this->adapter->isSchemaSupported()) {
            $this->markTestSkipped('Structured output hasn\'t been implemented for this model');
        }

        $object = new SchemaObject();
        $object->addProperty('location', [
            'type' => SchemaObject::TYPE_STRING,
            'description' => 'The city and state, e.g. San Francisco, CA',
        ]);
        $object->addProperty('unit', [
            'type' => SchemaObject::TYPE_STRING,
            'enum' => ['celsius', 'fahrenheit'],
            'description' => 'The unit of temperature, either "celsius" or "fahrenheit"',
        ]);

        $schema = new Schema(
            'get_weather',
            'Get the current weather in a given location in well structured JSON',
            $object,
            $object->getNames()
        );

        $this->agent->setSchema($schema);

        $messages = $this->conversation
            ->message(new User('user-2', 'Test User'), new Message('What is the weather in San Francisco in celsius?'))
            ->send();

        $content = $messages->getContent();

        $json = json_decode($content, true);
        $this->assertNotNull($json, 'JSON decoding failed');
        $this->assertIsArray($json, 'Decoded content must be an array');

        $this->assertArrayHasKey('location', $json);
        $this->assertArrayHasKey('unit', $json);

        $this->assertIsString($json['location'], 'Location must be a string');
        $this->assertIsString($json['unit'], 'Unit must be a string');

        $this->assertStringContainsString('San Francisco', $json['location']);
        $this->assertSame('celsius', $json['unit']);
    }

    public function testTokenCounting(): void
    {
        $this->conversation->countInputTokens(10);
        $this->assertSame(10, $this->conversation->getInputTokens());

        $this->conversation->countInputTokens(5);
        $this->assertSame(15, $this->conversation->getInputTokens());

        $this->conversation->countOutputTokens(20);
        $this->assertSame(20, $this->conversation->getOutputTokens());

        $this->conversation->countOutputTokens(10);
        $this->assertSame(30, $this->conversation->getOutputTokens());

        $this->assertSame(45, $this->conversation->getTotalTokens());
    }

    public function testListener(): void
    {
        $streamed = '';
        $testListener = function (string $token) use (&$streamed): void {
            $streamed .= $token;
        };

        $result = $this->conversation->listen($testListener);

        $this->assertSame($this->conversation, $result);
        $this->assertSame($testListener, $this->conversation->getListener());

        $response = $this->conversation
            ->message(new User('user-listen-1', 'Test User'), new Message('Write a detailed explanation of how rain forms in the atmosphere, and make it long: at least 1200 characters.'))
            ->send();

        $this->assertNotSame('', $streamed, 'Expected listener to receive streamed output');
        $this->assertSame($response->getContent(), $streamed, 'Listener stream must match full response content');
        $this->assertGreaterThan(500, strlen($streamed), 'Expected a large streamed payload to validate chunked listener behavior');
    }
}
