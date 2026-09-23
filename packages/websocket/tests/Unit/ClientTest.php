<?php

declare(strict_types=1);

namespace Utopia\WebSocket\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\WebSocket\Client;

final class ClientTest extends TestCase
{
    private Client $client;
    private string $testUrl = 'ws://localhost:8080';

    protected function setUp(): void
    {
        $this->client = new Client($this->testUrl);
    }

    public function testConstructorWithValidUrl(): void
    {
        $client = new Client($this->testUrl);
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorWithInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('invalid-url');
    }

    public function testConstructorWithCustomOptions(): void
    {
        $options = [
            'headers' => ['Authorization' => 'Bearer token'],
            'timeout' => 60,
        ];
        $client = new Client($this->testUrl, $options);
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testEventHandlers(): void
    {
        $callback = static function (): void {};

        $this->assertSame($this->client, $this->client->onMessage($callback));
        $this->assertSame($this->client, $this->client->onClose($callback));
        $this->assertSame($this->client, $this->client->onError($callback));
        $this->assertSame($this->client, $this->client->onOpen($callback));
        $this->assertSame($this->client, $this->client->onPing($callback));
        $this->assertSame($this->client, $this->client->onPong($callback));
    }

    public function testIsConnected(): void
    {
        $this->assertFalse($this->client->isConnected());
    }

    public function testSendWithoutConnection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected to WebSocket server');
        $this->client->send('test message');
    }

    public function testReceiveWithoutConnection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected to WebSocket server');
        $this->client->receive();
    }

    public function testListen(): void
    {
        try {
            $messageReceived = false;
            $testMessage = 'Hello WebSocket!';

            $this->client->onMessage(function ($data) use (&$messageReceived, $testMessage): void {
                $messageReceived = true;
                $this->assertEquals($testMessage, $data);
            });

            // Mock the client's recv method to simulate receiving a message
            $mockFrame = new \Swoole\WebSocket\Frame();
            $mockFrame->opcode = WEBSOCKET_OPCODE_TEXT;
            $mockFrame->data = $testMessage;

            $swooleClient = $this->createMock(\Swoole\Coroutine\Http\Client::class);
        } catch (\Error $e) {
            if (str_contains($e->getMessage(), 'enum_exists')) {
                $this->markTestSkipped('Test skipped due to enum_exists compatibility issue');
            }
            throw $e;
        }

        $swooleClient->expects($this->exactly(2))
            ->method('recv')
            ->willReturnOnConsecutiveCalls($mockFrame, false);

        $swooleClient->errCode = SWOOLE_ERROR_CLIENT_NO_CONNECTION;

        // Use reflection to set the private properties
        $reflectionClass = new \ReflectionClass(Client::class);

        $connectedProperty = $reflectionClass->getProperty('connected');
        $connectedProperty->setValue($this->client, true);

        $clientProperty = $reflectionClass->getProperty('client');
        $clientProperty->setValue($this->client, $swooleClient);

        $this->client->listen();

        $this->assertTrue($messageReceived);
        $this->assertFalse($this->client->isConnected());
    }

    public function testListenWithError(): void
    {
        try {
            $errorReceived = false;
            $this->client->onError(function ($error) use (&$errorReceived): void {
                $errorReceived = true;
                $this->assertInstanceOf(\RuntimeException::class, $error);
            });

            $swooleClient = $this->createMock(\Swoole\Coroutine\Http\Client::class);
        } catch (\Error $e) {
            if (str_contains($e->getMessage(), 'enum_exists')) {
                $this->markTestSkipped('Test skipped due to enum_exists compatibility issue');
            }
            throw $e;
        }

        $swooleClient->expects($this->once())
            ->method('recv')
            ->willReturn(false);

        $swooleClient->errCode = 1; // Some error code that's not SWOOLE_ERROR_CLIENT_NO_CONNECTION
        $swooleClient->errMsg = 'Test error';

        // Use reflection to set the private properties
        $reflectionClass = new \ReflectionClass(Client::class);

        $connectedProperty = $reflectionClass->getProperty('connected');
        $connectedProperty->setValue($this->client, true);

        $clientProperty = $reflectionClass->getProperty('client');
        $clientProperty->setValue($this->client, $swooleClient);

        $this->client->listen();

        $this->assertTrue($errorReceived);
        $this->assertFalse($this->client->isConnected());
    }
}
