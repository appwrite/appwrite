<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Ahc\Jwt\JWT;
use Appwrite\Deployment\Token;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');
    }

    protected function tearDown(): void
    {
        \putenv($this->previous === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->previous);
    }

    public function testVerifiesWhatItSigned(): void
    {
        $token = Token::sign('deployment1', Token::TYPE_SOURCE, 60, 'project1');

        $this->assertTrue(Token::verify($token, 'deployment1', Token::TYPE_SOURCE, 'project1'));
    }

    public function testIsBoundToDeploymentTypeAndProject(): void
    {
        $token = Token::sign('deployment1', Token::TYPE_SOURCE, 60, 'project1');

        $this->assertFalse(Token::verify($token, 'deployment2', Token::TYPE_SOURCE, 'project1'));
        $this->assertFalse(Token::verify($token, 'deployment1', 'output', 'project1'));
        // Uploaders choose deployment ids, so another project may hold the same one
        $this->assertFalse(Token::verify($token, 'deployment1', Token::TYPE_SOURCE, 'project2'));
        $this->assertFalse(Token::verify($token, 'deployment1', Token::TYPE_SOURCE, ''));
    }

    public function testRejectsEmptyAndForeignTokens(): void
    {
        $this->assertFalse(Token::verify('', 'deployment1', Token::TYPE_SOURCE, 'project1'));
        $this->assertFalse(Token::verify('not-a-token', 'deployment1', Token::TYPE_SOURCE, 'project1'));

        \putenv('_APP_OPENSSL_KEY_V1=another-key');
        $foreign = Token::sign('deployment1', Token::TYPE_SOURCE, 60, 'project1');
        \putenv('_APP_OPENSSL_KEY_V1=unit-test-key');

        $this->assertFalse(Token::verify($foreign, 'deployment1', Token::TYPE_SOURCE, 'project1'));
    }

    public function testExpires(): void
    {
        // Signed as Token::sign() does, with an expiry already in the past
        $expired = (new JWT('unit-test-key', 'HS256', 60))->encode([
            'deploymentId' => 'deployment1',
            'type' => Token::TYPE_SOURCE,
            'projectId' => 'project1',
            'exp' => \time() - 1,
        ]);

        $this->assertFalse(Token::verify($expired, 'deployment1', Token::TYPE_SOURCE, 'project1'));
    }
}
