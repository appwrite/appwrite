<?php

declare(strict_types=1);

namespace Utopia\Distribution\Tests\Store\Apple;

use PHPUnit\Framework\TestCase;
use Utopia\Distribution\Store\Apple\Webhook;

final class WebhookTest extends TestCase
{
    private const string BODY = 'Hello, World!';

    private const string SECRET = 'This is my secret';

    private const string SIGNATURE = 'hmacsha256=7f062172b01cb00b53ca068614674a3d982a34062a0f5d37687d5e3377e54657';

    public function testSignMatchesAppleExample(): void
    {
        $this->assertSame(self::SIGNATURE, Webhook::sign(self::BODY, self::SECRET));
    }

    public function testVerifyAcceptsAppleExample(): void
    {
        $this->assertTrue(Webhook::verify(self::BODY, self::SECRET, self::SIGNATURE));
    }

    public function testVerifyRejectsChangedBody(): void
    {
        $this->assertFalse(Webhook::verify('Hello, World?', self::SECRET, self::SIGNATURE));
    }

    public function testVerifyRejectsOtherSecret(): void
    {
        $this->assertFalse(Webhook::verify(self::BODY, 'This is not my secret', self::SIGNATURE));
    }

    public function testVerifyRejectsSignatureWithoutPrefix(): void
    {
        $this->assertFalse(Webhook::verify(self::BODY, self::SECRET, substr(self::SIGNATURE, \strlen(Webhook::PREFIX))));
    }

    public function testVerifyRejectsEmptySecret(): void
    {
        $this->assertFalse(Webhook::verify(self::BODY, '', Webhook::sign(self::BODY, '')));
    }

    public function testHeaderIsAppleSignature(): void
    {
        $this->assertSame('x-apple-signature', Webhook::HEADER);
    }

    public function testDefaultEventsAreTheThreeStateEvents(): void
    {
        $this->assertSame([
            'APP_STORE_VERSION_APP_VERSION_STATE_UPDATED',
            'BUILD_UPLOAD_STATE_UPDATED',
            'BUILD_BETA_DETAIL_EXTERNAL_BUILD_STATE_UPDATED',
        ], Webhook::EVENTS);
    }
}
