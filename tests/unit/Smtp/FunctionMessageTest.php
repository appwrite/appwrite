<?php

declare(strict_types=1);

namespace Tests\Unit\Smtp;

use Appwrite\Event\Message\Func;
use PHPUnit\Framework\TestCase;

final class FunctionMessageTest extends TestCase
{
    public function test_email_queue_payload_contains_storage_reference(): void
    {
        $message = new Func(
            functionId: 'function',
            type: 'email',
            bodyPath: '/storage/functions/app-project/smtp/delivery/email.json',
        );

        $payload = $message->toArray();

        $this->assertSame('email', $payload['type']);
        $this->assertSame('/storage/functions/app-project/smtp/delivery/email.json', $payload['bodyPath']);
        $this->assertSame('', $payload['body']);
        $this->assertSame($payload, Func::fromArray($payload)->toArray());
    }
}
