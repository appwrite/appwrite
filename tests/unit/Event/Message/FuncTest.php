<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Message;

use Appwrite\Event\Message\Func;
use PHPUnit\Framework\TestCase;

final class FuncTest extends TestCase
{
    public function testEventBodyPreservesObjectsThroughQueueSerialization(): void
    {
        $message = Func::fromEvent(
            event: 'users.[userId].delete',
            params: ['userId' => 'user-id'],
            payload: [
                '$id' => 'user-id',
                'prefs' => new \stdClass(),
                'targets' => [],
                'settings' => [
                    'options' => new \stdClass(),
                    'values' => [],
                    'mapping' => (object) ['0' => 'zero'],
                ],
            ],
        );

        // Redis and NATS decode queue envelopes into associative arrays.
        $received = Func::fromArray(\json_decode(\json_encode($message->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertJson($received->body);
        $body = \json_decode($received->body, false, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('user-id', $body->{'$id'});
        $this->assertInstanceOf(\stdClass::class, $body->prefs);
        $this->assertSame([], $body->targets);
        $this->assertInstanceOf(\stdClass::class, $body->settings->options);
        $this->assertSame([], $body->settings->values);
        $this->assertInstanceOf(\stdClass::class, $body->settings->mapping);
        $this->assertSame('zero', $body->settings->mapping->{'0'});
        $this->assertContains('users.user-id.delete', $received->events);
    }

    public function testEventBodyDoesNotDuplicatePayloadWithStableShape(): void
    {
        $payload = [
            '$id' => 'user-id',
            'prefs' => ['theme' => 'dark'],
            'targets' => [],
            'content' => \str_repeat('content', 10000),
        ];
        $message = Func::fromEvent(
            event: 'users.[userId].delete',
            params: ['userId' => 'user-id'],
            payload: $payload,
        );

        $received = Func::fromArray(\json_decode(\json_encode($message->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertSame('', $received->body);
        $this->assertSame($payload, $received->payload);
        $this->assertSame([], $received->payload['targets']);
    }

    public function testLegacyMessagesRetainPayloadWithoutBody(): void
    {
        $message = Func::fromArray([
            'events' => ['users.user-id.delete'],
            'payload' => ['$id' => 'user-id', 'prefs' => ['theme' => 'dark']],
        ]);

        $this->assertSame('', $message->body);
        $this->assertSame(['$id' => 'user-id', 'prefs' => ['theme' => 'dark']], $message->payload);
    }
}
