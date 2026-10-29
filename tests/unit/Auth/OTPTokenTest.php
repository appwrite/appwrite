<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use Appwrite\Auth\OTPToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OTPTokenTest extends TestCase
{
    public function testHeaders(): void
    {
        $token = OTPToken::from('123456', 'https://example.com');

        $this->assertNotNull($token);
        $this->assertSame(['OTP-Token' => '"123456"; origin="https://example.com"'], $token->toHeaders());
    }

    public function testEscapesString(): void
    {
        $token = OTPToken::from('a"b\\c', 'https://example.com');

        $this->assertNotNull($token);
        $this->assertSame('"a\\"b\\\\c"; origin="https://example.com"', $token->getValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalOrigins(): array
    {
        return [
            'https' => ['https://example.com', 'https://example.com'],
            'uppercase' => ['HTTPS://Example.COM', 'https://example.com'],
            'default https port' => ['https://example.com:443', 'https://example.com'],
            'custom port' => ['https://example.com:8443', 'https://example.com:8443'],
            'localhost' => ['http://localhost', 'http://localhost'],
            'localhost port' => ['http://localhost:3000', 'http://localhost:3000'],
            'default http port' => ['http://localhost:80', 'http://localhost'],
            'localhost subdomain' => ['http://app.localhost', 'http://app.localhost'],
            'ipv4 loopback' => ['http://127.0.0.1:5173', 'http://127.0.0.1:5173'],
            'ipv6 loopback' => ['http://[::1]:5173', 'http://[::1]:5173'],
        ];
    }

    #[DataProvider('canonicalOrigins')]
    public function testCanonicalizesOrigin(string $origin, string $expected): void
    {
        $this->assertSame($expected, OTPToken::from('123456', $origin)?->origin);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unboundTokens(): array
    {
        return [
            'empty origin' => ['123456', ''],
            'opaque origin' => ['123456', 'null'],
            'insecure http' => ['123456', 'http://example.com'],
            'lookalike localhost' => ['123456', 'http://localhost.example.com'],
            'non loopback ip' => ['123456', 'http://10.0.0.1'],
            'custom scheme' => ['123456', 'appwrite-callback-123://callback'],
            'extension' => ['123456', 'chrome-extension://abcdefghijklmnop'],
            'path' => ['123456', 'https://example.com/login'],
            'trailing slash' => ['123456', 'https://example.com/'],
            'query' => ['123456', 'https://example.com?a=b'],
            'credentials' => ['123456', 'https://user@example.com'],
            'non ascii' => ['123456', 'https://exämple.com'],
            'empty code' => ['', 'https://example.com'],
            'control in code' => ["123\n456", 'https://example.com'],
            'non ascii code' => ['１２３４５６', 'https://example.com'],
        ];
    }

    #[DataProvider('unboundTokens')]
    public function testRejects(string $code, string $origin): void
    {
        $this->assertNull(OTPToken::from($code, $origin));
    }
}
