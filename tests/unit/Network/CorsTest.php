<?php

namespace Tests\Unit\Network;

use Appwrite\Network\Cors;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    public function testWildcardWithCredentialsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cors(
            allowedHosts: ['*'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: true
        );
    }

    public function testWildcardAllowsAnyOrigin(): void
    {
        $cors = new Cors(
            allowedHosts: ['*'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false
        );

        $result = $cors->headers('https://foo.com');

        $this->assertSame('https://foo.com', $result[Cors::HEADER_ALLOW_ORIGIN]);
    }

    public function testSubdomainWildcardAllowsAnySubdomain(): void
    {
        $cors = new Cors(
            allowedHosts: ['*.example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false
        );

        $result = $cors->headers('https://foo.example.com');

        $this->assertSame('https://foo.example.com', $result[Cors::HEADER_ALLOW_ORIGIN]);
    }

    public function testEmptyOriginReturnsStaticHeadersOnly(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false
        );

        $result = $cors->headers('');

        $this->assertArrayNotHasKey(Cors::HEADER_ALLOW_ORIGIN, $result);
        $this->assertSame('false', $result[Cors::HEADER_ALLOW_CREDENTIALS]);
        $this->assertSame('GET', $result[Cors::HEADER_ALLOW_METHODS]);
    }

    public function testInvalidOriginReturnsStaticHeadersOnly(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false
        );

        $result = $cors->headers('%%%not-a-url%%%');

        $this->assertArrayNotHasKey(Cors::HEADER_ALLOW_ORIGIN, $result);
    }

    public function testUnlistedOriginReturnsStaticHeadersOnly(): void
    {
        $cors = new Cors(
            allowedHosts: ['allowed.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false
        );

        $result = $cors->headers('https://forbidden.com');

        $this->assertArrayNotHasKey(Cors::HEADER_ALLOW_ORIGIN, $result);
    }

    public function testAllowedOriginIsReturned(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['POST'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: true
        );

        $result = $cors->headers('https://example.com');

        $this->assertSame('https://example.com', $result[Cors::HEADER_ALLOW_ORIGIN]);
    }

    public function testOriginIsLowercasedForMatching(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false
        );

        $result = $cors->headers('HTTPS://EXAMPLE.COM');

        // Lowercase logic is in the class
        $this->assertSame('https://example.com', $result[Cors::HEADER_ALLOW_ORIGIN]);
    }

    public function testHeaderFormatting(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['GET', 'POST'],
            allowedHeaders: ['X-A', 'X-B'],
            exposedHeaders: ['E1', 'E2'],
            allowCredentials: true
        );

        $result = $cors->headers('https://example.com');

        $this->assertSame('GET, POST', $result[Cors::HEADER_ALLOW_METHODS]);
        $this->assertSame('X-A, X-B', $result[Cors::HEADER_ALLOW_HEADERS]);
        $this->assertSame('E1, E2', $result[Cors::HEADER_EXPOSE_HEADERS]);
        $this->assertSame('true', $result[Cors::HEADER_ALLOW_CREDENTIALS]);
    }

    public function testMaxAgeIncluded(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: false,
            maxAge: 999
        );

        $result = $cors->headers('https://example.com');

        $this->assertSame(999, $result[Cors::HEADER_MAX_AGE]);
    }
}
