<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\TimeoutException;

/**
 * Integration tests require a running NATS server at localhost:4222.
 */
final class RequestReplyTest extends TestCase
{
    private function getServerUrl(): string
    {
        return getenv('NATS_URL') ?: 'nats://127.0.0.1:4222';
    }

    public function testRequestReply(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        // Set up responder
        $sub = $conn->subscribe('test.echo', function ($msg) use ($conn): void {
            if ($msg->replyTo !== null) {
                $conn->publish($msg->replyTo, 'echo: ' . $msg->data);
            }
        });

        $response = $conn->request('test.echo', 'hello', 2.0);
        $this->assertSame('echo: hello', $response->data);

        $sub->unsubscribe();
        $conn->close();
    }

    public function testRequestTimeout(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        // Subscribe a silent responder so the server sees interest and does not
        // short-circuit with a "no responders" reply — the request must hang
        // until it times out.
        $conn->subscribe('test.silent', fn($msg) => null);

        $this->expectException(TimeoutException::class);
        $conn->request('test.silent', 'hello', 0.5);

        $conn->close();
    }

    public function testRequestNoResponders(): void
    {
        $conn = Connection::connect($this->getServerUrl());

        // With no subscriber on the subject the server replies immediately with
        // a 503 "no responders" status rather than letting the request hang.
        $this->expectException(NatsException::class);
        $this->expectExceptionMessage('No responders for request');
        $conn->request('test.no-responder-' . uniqid(), 'hello', 2.0);

        $conn->close();
    }
}
