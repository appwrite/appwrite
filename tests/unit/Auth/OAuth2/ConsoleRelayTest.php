<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\OAuth2;

use Appwrite\Auth\OAuth2\ConsoleRelay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleRelayTest extends TestCase
{
    /**
     * Mirrors Cloud after the console moved to appwrite.io while the API
     * stayed on *.cloud.appwrite.io.
     *
     * @return array<string, mixed>
     */
    private static function cloudPlatform(): array
    {
        return [
            'consoleUrl' => 'https://appwrite.io',
            'apiHostname' => 'fra.cloud.appwrite.io',
            'consoleHostname' => 'appwrite.io',
            'hostnames' => [
                'fra.cloud.appwrite.io',
                'appwrite.io',
            ],
        ];
    }

    public static function matchesProvider(): \Iterator
    {
        $path = '/auth/oauth2/success';

        yield 'console host' => ['appwrite.io', $path, true];
        yield 'regional API host' => ['fra.cloud.appwrite.io', $path, true];
        yield 'request API host not in env list' => ['cloud.appwrite.io', $path, true, 'cloud.appwrite.io'];
        yield 'customer host same path' => ['myapp.com', $path, false];
        yield 'API host custom path' => ['fra.cloud.appwrite.io', '/custom/callback', false];
        yield 'empty host' => ['', $path, false];
    }

    #[DataProvider('matchesProvider')]
    public function testMatchesAppwriteOwnedRelayHosts(
        string $host,
        string $path,
        bool $expected,
        ?string $requestHostname = null,
    ): void {
        $this->assertSame(
            $expected,
            ConsoleRelay::matches(
                $host,
                $path,
                '/auth/oauth2/success',
                self::cloudPlatform(),
                $requestHostname,
            ),
        );
    }

    public function testNormalizeRewritesApiHostRelayOntoConsoleUrl(): void
    {
        $normalized = ConsoleRelay::normalize(
            'https://cloud.appwrite.io/auth/oauth2/success?foo=1',
            '/auth/oauth2/success',
            'https://appwrite.io',
            self::cloudPlatform(),
            'cloud.appwrite.io',
        );

        $this->assertSame('https://appwrite.io/auth/oauth2/success?foo=1', $normalized);
    }

    public function testNormalizeLeavesConsoleHostUnchanged(): void
    {
        $url = 'https://appwrite.io/auth/oauth2/success?project=abc';
        $this->assertSame(
            $url,
            ConsoleRelay::normalize(
                $url,
                '/auth/oauth2/success',
                'https://appwrite.io',
                self::cloudPlatform(),
            ),
        );
    }

    public function testNormalizeLeavesCustomerCallbackUnchanged(): void
    {
        $url = 'https://myapp.com/auth/oauth2/success';
        $this->assertSame(
            $url,
            ConsoleRelay::normalize(
                $url,
                '/auth/oauth2/success',
                'https://appwrite.io',
                self::cloudPlatform(),
            ),
        );
    }

    public function testOwnedHostnamesDeduplicate(): void
    {
        $hosts = ConsoleRelay::ownedHostnames(self::cloudPlatform(), 'cloud.appwrite.io');
        $this->assertSame(
            ['fra.cloud.appwrite.io', 'appwrite.io', 'cloud.appwrite.io'],
            $hosts,
        );
    }
}
