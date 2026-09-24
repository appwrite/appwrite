<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\RedirectUris;

final class RedirectUrisTest extends TestCase
{
    /**
     * @param array<int, mixed> $registered
     */
    #[DataProvider('matchingProvider')]
    public function testMatches(array $registered, string $presented, bool $expected, bool $allowLoopback = false): void
    {
        $this->assertSame($expected, RedirectUris::from($registered)->matches($presented, $allowLoopback));
    }

    public function testFromFiltersMalformedEntries(): void
    {
        $uris = RedirectUris::from(['https://example.com/cb', '', 42, null, ['nested']]);

        $this->assertSame(['https://example.com/cb'], $uris->toArray());
        $this->assertTrue($uris->matches('https://example.com/cb'));
    }

    /**
     * @return \Iterator<string, array{registered: array<int, mixed>, presented: string, expected: bool, allowLoopback?: bool}>
     */
    public static function matchingProvider(): \Iterator
    {
        // Exact matching.
        yield 'exact match' => [
            'registered' => ['https://example.com/cb'],
            'presented' => 'https://example.com/cb',
            'expected' => true,
        ];
        yield 'exact miss' => [
            'registered' => ['https://example.com/cb'],
            'presented' => 'https://example.com/other',
            'expected' => false,
        ];
        yield 'empty presented' => [
            'registered' => ['https://example.com/cb'],
            'presented' => '',
            'expected' => false,
        ];
        yield 'empty registered list' => [
            'registered' => [],
            'presented' => 'https://example.com/cb',
            'expected' => false,
        ];

        // The RFC 8252 carve-out is opt-in: off by default (confidential clients).
        yield 'loopback different port without opt-in' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => false,
        ];
        yield 'loopback IPv4 different port without opt-in' => [
            'registered' => ['http://127.0.0.1:3118/callback'],
            'presented' => 'http://127.0.0.1:54155/callback',
            'expected' => false,
        ];
        yield 'loopback IPv6 different port without opt-in' => [
            'registered' => ['http://[::1]:3118/callback'],
            'presented' => 'http://[::1]:54155/callback',
            'expected' => false,
        ];
        yield 'loopback portless registered, ported presented without opt-in' => [
            'registered' => ['http://localhost/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => false,
        ];
        yield 'loopback host case difference without opt-in' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://LOCALHOST:3118/callback',
            'expected' => false,
        ];
        yield 'loopback empty path normalization without opt-in' => [
            'registered' => ['http://localhost:3118'],
            'presented' => 'http://localhost:3118/',
            'expected' => false,
        ];

        // Exact matches never depend on the opt-in, loopback or not.
        yield 'exact loopback match without opt-in' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:3118/callback',
            'expected' => true,
        ];

        // RFC 8252 loopback port variance.
        yield 'loopback different port' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback portless registered, ported presented' => [
            'registered' => ['http://localhost/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback ported registered, portless presented' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback IPv4 different port' => [
            'registered' => ['http://127.0.0.1:3118/callback'],
            'presented' => 'http://127.0.0.1:54155/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback IPv6 different port' => [
            'registered' => ['http://[::1]:3118/callback'],
            'presented' => 'http://[::1]:54155/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback with matching query' => [
            'registered' => ['http://localhost:3118/callback?flow=cli'],
            'presented' => 'http://localhost:54155/callback?flow=cli',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback empty path normalizes to root' => [
            'registered' => ['http://localhost:3118'],
            'presented' => 'http://localhost:54155/',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback host is case-insensitive' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://LOCALHOST:54155/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];
        yield 'loopback match among multiple registered' => [
            'registered' => ['https://example.com/cb', 'http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => true,
            'allowLoopback' => true,
        ];

        // Host strictness.
        yield 'localhost does not match 127.0.0.1' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://127.0.0.1:54155/callback',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback lookalike host' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost.evil.com:3118/callback',
            'expected' => false,
            'allowLoopback' => true,
        ];

        // Scheme strictness.
        yield 'https loopback stays exact-only' => [
            'registered' => ['https://localhost:3118/callback'],
            'presented' => 'https://localhost:54155/callback',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'custom scheme stays exact-only' => [
            'registered' => ['myapp://localhost:3118/callback'],
            'presented' => 'myapp://localhost:54155/callback',
            'expected' => false,
            'allowLoopback' => true,
        ];

        // Component strictness.
        yield 'loopback different path' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/other',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback path is case-sensitive' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/Callback',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback different query' => [
            'registered' => ['http://localhost:3118/callback?flow=cli'],
            'presented' => 'http://localhost:54155/callback?flow=web',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback missing registered query' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback?flow=cli',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback presented fragment stays exact-only' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://localhost:54155/callback#fragment',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback registered fragment stays exact-only' => [
            'registered' => ['http://localhost:3118/callback#fragment'],
            'presented' => 'http://localhost:54155/callback#fragment',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'loopback userinfo stays exact-only' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://user@localhost:54155/callback',
            'expected' => false,
            'allowLoopback' => true,
        ];

        // Robustness.
        yield 'malformed presented URI' => [
            'registered' => ['http://localhost:3118/callback'],
            'presented' => 'http://',
            'expected' => false,
            'allowLoopback' => true,
        ];
        yield 'malformed registered URI never matches loopback' => [
            'registered' => ['http://'],
            'presented' => 'http://localhost:54155/callback',
            'expected' => false,
            'allowLoopback' => true,
        ];
    }
}
