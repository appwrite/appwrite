<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\ClientIdentifierUrl;
use Utopia\Auth\OAuth2\InvalidClientMetadataException;

final class ClientIdentifierUrlTest extends TestCase
{
    #[DataProvider('validUrlProvider')]
    public function testFromString(string $value, string $host, bool $allowHttp = false): void
    {
        $identifier = ClientIdentifierUrl::fromString($value, $allowHttp);

        $this->assertSame($value, $identifier->toString());
        $this->assertSame($host, $identifier->host());
    }

    #[DataProvider('invalidUrlProvider')]
    public function testRejectsInvalidUrl(string $value, bool $allowHttp = false): void
    {
        $this->expectException(InvalidClientMetadataException::class);

        ClientIdentifierUrl::fromString($value, $allowHttp);
    }

    public function testCandidateDetection(): void
    {
        $this->assertTrue(ClientIdentifierUrl::isCandidate('https://client.example/metadata'));
        $this->assertTrue(ClientIdentifierUrl::isCandidate('HTTPS://client.example/metadata'));
        $this->assertTrue(ClientIdentifierUrl::isCandidate('http://localhost/metadata'));
        $this->assertFalse(ClientIdentifierUrl::isCandidate('client.example/metadata'));
        $this->assertFalse(ClientIdentifierUrl::isCandidate('opaque-client-id'));
    }

    /**
     * @return \Iterator<string, array{value: string, host: string, allowHttp?: bool}>
     */
    public static function validUrlProvider(): \Iterator
    {
        yield 'path' => [
            'value' => 'https://client.example/metadata',
            'host' => 'client.example',
        ];
        yield 'root path is allowed' => [
            'value' => 'https://client.example/',
            'host' => 'client.example',
        ];
        yield 'port and query' => [
            'value' => 'https://client.example:8443/metadata?version=1',
            'host' => 'client.example',
        ];
        yield 'development http' => [
            'value' => 'http://localhost/metadata',
            'host' => 'localhost',
            'allowHttp' => true,
        ];
    }

    /**
     * @return \Iterator<string, array{value: string, allowHttp?: bool}>
     */
    public static function invalidUrlProvider(): \Iterator
    {
        yield 'empty' => ['value' => ''];
        yield 'opaque identifier' => ['value' => 'opaque-client-id'];
        yield 'http by default' => ['value' => 'http://client.example/metadata'];
        yield 'missing path' => ['value' => 'https://client.example'];
        yield 'missing host' => ['value' => 'https:///metadata'];
        yield 'userinfo' => ['value' => 'https://user:password@client.example/metadata'];
        yield 'fragment' => ['value' => 'https://client.example/metadata#fragment'];
        yield 'single dot segment' => ['value' => 'https://client.example/a/./metadata'];
        yield 'double dot segment' => ['value' => 'https://client.example/a/../metadata'];
        yield 'invalid characters' => ['value' => 'https://client.example/a path'];
    }
}
