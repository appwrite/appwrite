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

        $this->assertInstanceOf(OTPToken::class, $token);
        $this->assertSame(['OTP-Token' => '"123456"; origin="https://example.com"'], $token->toHeaders());
    }

    public function testEscapesString(): void
    {
        $token = OTPToken::from('a"b\\c', 'https://example.com');

        $this->assertInstanceOf(OTPToken::class, $token);
        $this->assertSame('"a\\"b\\\\c"; origin="https://example.com"', $token->getValue());
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function canonicalOrigins(): \Iterator
    {
        yield 'https' => ['https://example.com', 'https://example.com'];
        yield 'uppercase' => ['HTTPS://Example.COM', 'https://example.com'];
        yield 'default https port' => ['https://example.com:443', 'https://example.com'];
        yield 'custom port' => ['https://example.com:8443', 'https://example.com:8443'];
        yield 'localhost' => ['http://localhost', 'http://localhost'];
        yield 'localhost port' => ['http://localhost:3000', 'http://localhost:3000'];
        yield 'default http port' => ['http://localhost:80', 'http://localhost'];
        yield 'localhost subdomain' => ['http://app.localhost', 'http://app.localhost'];
        yield 'ipv4 loopback' => ['http://127.0.0.1:5173', 'http://127.0.0.1:5173'];
        yield 'ipv6 loopback' => ['http://[::1]:5173', 'http://[::1]:5173'];
    }

    #[DataProvider('canonicalOrigins')]
    public function testCanonicalizesOrigin(string $origin, string $expected): void
    {
        $this->assertSame($expected, OTPToken::from('123456', $origin)?->origin);
    }

    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function unboundTokens(): \Iterator
    {
        yield 'empty origin' => ['123456', ''];
        yield 'opaque origin' => ['123456', 'null'];
        yield 'insecure http' => ['123456', 'http://example.com'];
        yield 'lookalike localhost' => ['123456', 'http://localhost.example.com'];
        yield 'non loopback ip' => ['123456', 'http://10.0.0.1'];
        yield 'custom scheme' => ['123456', 'appwrite-callback-123://callback'];
        yield 'extension' => ['123456', 'chrome-extension://abcdefghijklmnop'];
        yield 'path' => ['123456', 'https://example.com/login'];
        yield 'trailing slash' => ['123456', 'https://example.com/'];
        yield 'query' => ['123456', 'https://example.com?a=b'];
        yield 'credentials' => ['123456', 'https://user@example.com'];
        yield 'non ascii' => ['123456', 'https://exämple.com'];
        yield 'empty code' => ['', 'https://example.com'];
        yield 'control in code' => ["123\n456", 'https://example.com'];
        yield 'non ascii code' => ['１２３４５６', 'https://example.com'];
    }

    #[DataProvider('unboundTokens')]
    public function testRejects(string $code, string $origin): void
    {
        $this->assertNull(OTPToken::from($code, $origin));
    }
}
