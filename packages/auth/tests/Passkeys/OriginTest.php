<?php

declare(strict_types=1);

namespace Utopia\Auth\Tests\Passkeys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\Passkeys\Origin;

final class OriginTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function origins(): array
    {
        return [
            'rp id' => ['example.com', 'https://example.com', 'https://example.com'],
            'trailing slash' => ['example.com', 'https://example.com/', 'https://example.com'],
            'subdomain with default port' => ['example.com', 'https://app.example.com:443', 'https://app.example.com'],
            'custom port' => ['example.com', 'https://example.com:8443', 'https://example.com:8443'],
            'http' => ['example.com', 'http://example.com', null],
            'other domain' => ['example.com', 'https://evil.com', null],
            'suffix lookalike' => ['example.com', 'https://notexample.com', null],
            'path' => ['example.com', 'https://example.com/login', null],
            'credentials' => ['example.com', 'https://user:pass@example.com', null],
            'query' => ['example.com', 'https://example.com?next=1', null],
            'empty fragment' => ['example.com', 'https://example.com#', null],
            'uppercase' => ['example.com', 'https://EXAMPLE.com', null],
            'no scheme' => ['example.com', 'example.com', null],
            'localhost over http' => ['localhost', 'http://localhost:3000', 'http://localhost:3000'],
            'localhost default port' => ['localhost', 'http://localhost:80', 'http://localhost'],
            'localhost over https' => ['localhost', 'https://localhost', 'https://localhost'],
            'loopback ip' => ['localhost', 'http://127.0.0.1:3000', null],
        ];
    }

    #[DataProvider('origins')]
    public function testNormalize(string $rpId, string $origin, ?string $expected): void
    {
        $this->assertSame($expected, (new Origin($rpId))->normalize($origin));
    }
}
