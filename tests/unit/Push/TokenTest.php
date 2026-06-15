<?php

namespace Tests\Unit\Push;

use Appwrite\Push\Token;
use PHPUnit\Framework\TestCase;

class TokenTest extends TestCase
{
    private const KEY = 'secret-for-tests-only';

    public function testIssueAndVerifyDeviceToken(): void
    {
        $tokens = new Token(self::KEY);

        $jwt = $tokens->issueForDevice('device-1', 'user-1', 'project-1', expirySeconds: 60);

        $claims = $tokens->verify($jwt);

        $this->assertNotNull($claims);
        $this->assertSame('device-1', $claims['sub']);
        $this->assertSame('user-1', $claims['uid']);
        $this->assertSame('project-1', $claims['pid']);
        $this->assertSame(Token::SCOPE_DEVICE, $claims['scope']);
        $this->assertSame(Token::topicForDevice('device-1'), $claims['topic']);
        $this->assertGreaterThan(\time(), $claims['exp']);
    }

    public function testIssueAndVerifyServerToken(): void
    {
        $tokens = new Token(self::KEY);

        $jwt = $tokens->issueForServer('messaging-worker-1', expirySeconds: 30);

        $claims = $tokens->verify($jwt);

        $this->assertNotNull($claims);
        $this->assertSame('messaging-worker-1', $claims['sub']);
        $this->assertSame(Token::SCOPE_SERVER, $claims['scope']);
    }

    public function testTokenSignedWithDifferentKeyFailsVerification(): void
    {
        $issuer = new Token('key-a');
        $verifier = new Token('key-b');

        $jwt = $issuer->issueForDevice('device-1', 'user-1', 'project-1');

        $this->assertNull($verifier->verify($jwt));
    }

    public function testExpiredTokenFailsVerification(): void
    {
        $tokens = new Token(self::KEY);
        $jwt = $tokens->issueForDevice('device-1', 'user-1', 'project-1', expirySeconds: -10);

        $this->assertNull($tokens->verify($jwt));
    }

    public function testGarbageInputFailsVerification(): void
    {
        $tokens = new Token(self::KEY);

        $this->assertNull($tokens->verify('not.a.jwt'));
        $this->assertNull($tokens->verify(''));
        $this->assertNull($tokens->verify('only.two-segments'));
    }

    public function testEmptyKeyRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Token('');
    }

    public function testTopicForDeviceIsPrefixed(): void
    {
        $this->assertSame('appwrite/push/some-device', Token::topicForDevice('some-device'));
    }
}
