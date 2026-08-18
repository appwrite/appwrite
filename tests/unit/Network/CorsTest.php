<?php

declare(strict_types=1);

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

    public function testLoopbackOriginIsAllowed(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com', 'localhost'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: true
        );

        foreach (
            [
                'http://localhost',
                'http://localhost:3000',
                'http://127.0.0.1',
                'https://127.0.0.1:5173',
                'http://[::1]',
                'http://[::1]:3000',
            ] as $origin
        ) {
            $this->assertSame($origin, $cors->headers($origin)[Cors::HEADER_ALLOW_ORIGIN] ?? null);
        }
    }

    public function testLoopbackOriginIsEchoedExactly(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com', 'localhost'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: true
        );

        /**
         * Trusting loopback with credentials is only safe while the origin is
         * echoed back verbatim, since the browser requires a literal match.
         * A wildcard here would hand the allowance to every remote page.
         */
        $result = $cors->headers('http://127.0.0.1:5173');

        $this->assertSame('http://127.0.0.1:5173', $result[Cors::HEADER_ALLOW_ORIGIN]);
        $this->assertNotSame('*', $result[Cors::HEADER_ALLOW_ORIGIN]);
        $this->assertSame('true', $result[Cors::HEADER_ALLOW_CREDENTIALS]);
    }

    public function testLoopbackLookalikeOriginIsRejected(): void
    {
        $cors = new Cors(
            allowedHosts: ['example.com', 'localhost'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: true
        );

        foreach (
            [
                'http://127.0.0.1.evil.com',
                'http://localhost.evil.com',
                'http://128.0.0.1',
                'http://[2001:db8::1]',
                /* A prefix or substring match would wrongly accept these */
                'http://xlocalhost',
                'http://127.0.0.1x.example.com',
                'http://[::1].evil.com',
                'http://[::1]evil.com',
                /* Only the exact alias spellings resolve to localhost */
                'http://127.0.0.2',
                'http://[0:0:0:0:0:0:0:1]',
                'http://localhost.',
            ] as $origin
        ) {
            $this->assertArrayNotHasKey(Cors::HEADER_ALLOW_ORIGIN, $cors->headers($origin));
        }
    }

    public function testLoopbackRequiresLocalhostToBeAllowed(): void
    {
        /**
         * Credentialed CORS plus SameSite=None cookies means an accepted origin
         * can read authenticated responses, so a deployment that does not trust
         * localhost must not have any spelling of it reflected back.
         */
        $cors = new Cors(
            allowedHosts: ['example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['X-Test'],
            exposedHeaders: [],
            allowCredentials: true
        );

        foreach (
            [
                'http://localhost',
                'http://localhost:3000',
                'http://127.0.0.1',
                'https://127.0.0.1:5173',
                'http://[::1]',
                'http://[::1]:3000',
            ] as $origin
        ) {
            $this->assertArrayNotHasKey(Cors::HEADER_ALLOW_ORIGIN, $cors->headers($origin), 'Origin ' . $origin . ' was unexpectedly allowed');
        }
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
