<?php

declare(strict_types=1);

namespace Utopia\Mqtt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Mqtt\Client;

final class ClientTest extends TestCase
{
    public function testRejectsInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(':::not a url');
    }

    public function testRejectsUrlWithoutHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('mqtt://');
    }

    public function testStartsDisconnected(): void
    {
        $client = new Client('mqtt://broker.local:1883');

        $this->assertFalse($client->isConnected());
    }

    public function testSendBeforeConnectThrows(): void
    {
        $client = new Client('mqtt://broker.local');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected');
        $client->send('anything');
    }

    public function testReceiveBeforeConnectThrows(): void
    {
        $client = new Client('mqtts://broker.local');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not connected');
        $client->receive();
    }

    public function testHandlersAreChainable(): void
    {
        $client = new Client('mqtt://broker.local');

        $noop = fn () => null;
        $returned = $client
            ->onOpen($noop)
            ->onReceive($noop)
            ->onClose($noop)
            ->onError($noop);

        $this->assertSame($client, $returned);
    }
}
