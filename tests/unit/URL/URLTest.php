<?php

declare(strict_types=1);

namespace Tests\Unit\URL;

use Appwrite\URL\URL;
use PHPUnit\Framework\TestCase;

final class URLTest extends TestCase
{
    public function testParse(): void
    {
        $url = URL::parse('https://appwrite.io:8080/path?query=string&param=value');

        $this->assertEquals('https', $url['scheme']);
        $this->assertEquals('appwrite.io', $url['host']);
        $this->assertEquals('8080', $url['port']);
        $this->assertEquals('/path', $url['path']);
        $this->assertEquals('query=string&param=value', $url['query']);

        $url = URL::parse('https://appwrite.io');

        $this->assertEquals('https', $url['scheme']);
        $this->assertEquals('appwrite.io', $url['host']);
        $this->assertEquals(null, $url['port']);
        $this->assertEquals('', $url['path']);
        $this->assertEquals('', $url['query']);

        $url = URL::parse('appwrite-callback-project://');

        $this->assertEquals('appwrite-callback-project', $url['scheme']);
        $this->assertEquals('', $url['host']);
        $this->assertEquals(null, $url['port']);
        $this->assertEquals('', $url['path']);
        $this->assertEquals('', $url['query']);
    }

    public function testUnparse(): void
    {
        $url = URL::unparse([
            'scheme' => 'https',
            'host' => 'appwrite.io',
            'port' => 8080,
            'path' => '/path',
            'query' => 'query=string&param=value',
        ]);

        $this->assertSame('https://appwrite.io:8080/path?query=string&param=value', $url);

        $url = URL::unparse([
            'scheme' => 'https',
            'host' => 'appwrite.io',
            'port' => null,
            'path' => '/path',
            'query' => 'query=string&param=value',
        ]);

        $this->assertSame('https://appwrite.io/path?query=string&param=value', $url);

        $url = URL::unparse([
            'scheme' => 'https',
            'host' => 'appwrite.io',
            'port' => null,
            'path' => '',
            'query' => '',
        ]);

        $this->assertSame('https://appwrite.io/', $url);

        $url = URL::unparse([
            'scheme' => 'https',
            'host' => 'appwrite.io',
            'port' => null,
            'path' => '',
            'fragment' => 'bottom',
        ]);

        $this->assertSame('https://appwrite.io/#bottom', $url);

        $url = URL::unparse([
            'scheme' => 'https',
            'user' => 'eldad',
            'pass' => 'fux',
            'host' => 'appwrite.io',
            'port' => null,
            'path' => '',
            'fragment' => 'bottom',
        ]);

        $this->assertSame('https://eldad:fux@appwrite.io/#bottom', $url);

        $url = URL::unparse([
            'scheme' => 'https',
            'user' => '',
            'pass' => '',
            'host' => 'appwrite.io',
            'port' => null,
            'path' => '',
            'fragment' => '',
        ]);

        $this->assertSame('https://appwrite.io/#', $url);
    }

    public function testParseQuery(): void
    {
        $result = URL::parseQuery('param1=value1&param2=value2');

        $this->assertSame(['param1' => 'value1', 'param2' => 'value2'], $result);
    }

    public function testUnParseQuery(): void
    {
        $result = URL::unparseQuery(['param1' => 'value1', 'param2' => 'value2']);

        $this->assertSame('param1=value1&param2=value2', $result);
    }

    /**
     * RFC 3986 §5.4 reference resolution test cases.
     */
    public function testResolveLocationAbsolute(): void
    {
        // Absolute URL wins over the base
        $this->assertSame(
            'https://other.example/path',
            URL::resolveLocation('https://base.example/a/b', 'https://other.example/path')
        );

        // Different scheme too
        $this->assertSame(
            'http://other.example/',
            URL::resolveLocation('https://base.example/a/b', 'http://other.example/')
        );
    }

    public function testResolveLocationProtocolRelative(): void
    {
        // Inherits scheme from base
        $this->assertSame(
            'https://other.example/path',
            URL::resolveLocation('https://base.example/a/b', '//other.example/path')
        );

        // Including with a port
        $this->assertSame(
            'https://other.example:8080/path',
            URL::resolveLocation('https://base.example/', '//other.example:8080/path')
        );
    }

    public function testResolveLocationAbsolutePath(): void
    {
        $this->assertSame(
            'https://base.example/new',
            URL::resolveLocation('https://base.example/a/b', '/new')
        );

        // Preserves port
        $this->assertSame(
            'https://base.example:9000/new',
            URL::resolveLocation('https://base.example:9000/a/b', '/new')
        );
    }

    public function testResolveLocationRelativePath(): void
    {
        $this->assertSame(
            'https://base.example/a/c',
            URL::resolveLocation('https://base.example/a/b', 'c')
        );

        $this->assertSame(
            'https://base.example/a/b/c',
            URL::resolveLocation('https://base.example/a/b/', 'c')
        );
    }

    public function testResolveLocationQueryOnly(): void
    {
        // The bug Copilot called out: query-only must keep the base path
        $this->assertSame(
            'https://base.example/a/b?x=1',
            URL::resolveLocation('https://base.example/a/b', '?x=1')
        );

        // Replaces any base query
        $this->assertSame(
            'https://base.example/a/b?x=1',
            URL::resolveLocation('https://base.example/a/b?old=value', '?x=1')
        );
    }

    public function testResolveLocationFragmentOnly(): void
    {
        // Fragment-only must keep base path AND base query
        $this->assertSame(
            'https://base.example/a/b#section',
            URL::resolveLocation('https://base.example/a/b', '#section')
        );

        $this->assertSame(
            'https://base.example/a/b?x=1#section',
            URL::resolveLocation('https://base.example/a/b?x=1', '#section')
        );
    }

    public function testResolveLocationDotSegments(): void
    {
        // RFC 3986 §5.2.4 normalisation. `/c/d/..` resolves to `/c/`
        // (the trailing slash is significant — it's the directory of `d`).
        $this->assertSame(
            'https://base.example/c/',
            URL::resolveLocation('https://base.example/a/b', '/c/d/..')
        );

        $this->assertSame(
            'https://base.example/c/',
            URL::resolveLocation('https://base.example/a/b', '/c/d/../')
        );

        // From inside the directory `/a/b/`, `../c` goes up one level → `/a/c`
        $this->assertSame(
            'https://base.example/a/c',
            URL::resolveLocation('https://base.example/a/b/', '../c')
        );

        // From file `/a/b`, `../c` goes up to root → `/c`
        $this->assertSame(
            'https://base.example/c',
            URL::resolveLocation('https://base.example/a/b', '../c')
        );

        $this->assertSame(
            'https://base.example/a/b/c',
            URL::resolveLocation('https://base.example/a/b/', './c')
        );
    }

    public function testResolveLocationEmpty(): void
    {
        // Empty reference returns base unchanged
        $this->assertSame(
            'https://base.example/a/b',
            URL::resolveLocation('https://base.example/a/b', '')
        );

        // Whitespace-only reference is treated as empty
        $this->assertSame(
            'https://base.example/a/b',
            URL::resolveLocation('https://base.example/a/b', '   ')
        );
    }

    public function testResolveLocationPreservesAuthorityFromBase(): void
    {
        // user/pass survive relative resolution
        $this->assertSame(
            'https://user:pass@base.example/c',
            URL::resolveLocation('https://user:pass@base.example/a/b', '/c')
        );

        // port survives, and `c` relative to `/a/b` resolves to `/a/c`
        // (RFC 3986 §5.2.3 merge replaces the last segment of the base path).
        $this->assertSame(
            'http://base.example:8080/a/c',
            URL::resolveLocation('http://base.example:8080/a/b', 'c')
        );
    }
}
