<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Headers;
use Utopia\NATS\Message;
use Utopia\NATS\Request;

final class RequestsTest extends TestCase
{
    public function testHeadersAndIndependentRepliesAgainstServer(): void
    {
        $connection = Connection::connect(getenv('NATS_URL') ?: 'nats://127.0.0.1:14222');
        try {
            $subject = 'requests.' . bin2hex(random_bytes(8));
            $headers = new Headers();
            $headers->set('Trace', 'example');
            $received = [];
            $connection->subscribe($subject, static function (Message $message) use ($connection, &$received): void {
                $received[] = $message;
                $connection->publish($message->replyTo, $message->data);
            });
            $connection->flush();
            $results = [];
            $connection->requestBatch([
                new Request(subject: $subject, data: 'first', headers: $headers),
                new Request(subject: $subject . '.missing'),
                new Request(subject: $subject, data: 'third'),
            ], static function (int $index, Message|\Throwable $result) use (&$results): void {
                $results[$index] = $result;
            });
            $this->assertCount(3, $results);
            $this->assertSame('first', $results[0]->data);
            $this->assertInstanceOf(NatsException::class, $results[1]);
            $this->assertSame('third', $results[2]->data);
            $this->assertSame('example', $received[0]->headers->get('Trace'));
        } finally {
            $connection->close();
        }
    }
}
