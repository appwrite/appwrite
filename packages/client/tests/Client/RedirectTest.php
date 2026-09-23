<?php

declare(strict_types=1);

namespace Utopia\Tests\Client;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Redirect;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Uri;

final class RedirectTest extends TestCase
{
    public function testItResolvesRfc3986RelativeLocations(): void
    {
        $base = Uri::parse('http://example.test:8080/a/b/c');

        $this->assertSame('http://example.test:8080/a/final', (string) Redirect::resolve($base, '../final'));
        $this->assertSame('http://example.test:8080/a/b/final', (string) Redirect::resolve($base, './final'));
        $this->assertSame('http://example.test:8080/a/b/final', (string) Redirect::resolve($base, 'final'));
        $this->assertSame('http://example.test:8080/final', (string) Redirect::resolve($base, '/final'));
        $this->assertSame('http://example.test:8080/final', (string) Redirect::resolve($base, '../../final'));
    }

    public function testItPreservesRfc3986PathAndQuerySemantics(): void
    {
        $base = Uri::parse('http://example.test/a/b/c?old=1');

        $this->assertSame('http://example.test/a/b//final', (string) Redirect::resolve($base, '../b//final'));
        $this->assertSame('http://example.test/a/b/', (string) Redirect::resolve($base, './'));
        $this->assertSame('http://example.test/a/b/c', (string) Redirect::resolve($base, '?'));
        $this->assertSame('http://example.test/a/b/c?new=1', (string) Redirect::resolve($base, '?new=1'));
        $this->assertSame('http://example.test/a/b/c?old=1#result', (string) Redirect::resolve($base, '#result'));
    }

    public function testItResolvesAbsoluteHttpAndHttpsLocations(): void
    {
        $base = Uri::parse('http://example.test/a/b');

        $this->assertSame('http://other.test/x', (string) Redirect::resolve($base, 'http://other.test/x'));
        $this->assertSame('https://other.test/x', (string) Redirect::resolve($base, 'https://other.test/x'));
        $this->assertSame('http://cdn.test/img', (string) Redirect::resolve($base, '//cdn.test/img'));
    }

    public function testItKeepsSensitiveHeadersOnSameOriginRedirects(): void
    {
        $from = Uri::parse('http://example.test:8080/start');
        $to = Redirect::resolve($from, '/final');

        $this->assertFalse(Redirect::shouldStripSensitiveHeaders($from, $to));
        $this->assertTrue(Redirect::isSameOrigin($from, $to));
    }

    public function testItStripsSensitiveHeadersWhenTheHostChanges(): void
    {
        $from = Uri::parse('http://example.test/start');
        $to = Redirect::resolve($from, 'http://other.test/final');

        $this->assertTrue(Redirect::shouldStripSensitiveHeaders($from, $to));

        $request = new Request\Factory()
            ->createRequest(Method::GET, (string) $from)
            ->withHeader(Header::AUTHORIZATION, 'Bearer secret')
            ->withHeader(Header::COOKIE, 'session=1')
            ->withHeader('Cookie2', 'legacy=1')
            ->withHeader('Proxy-Authorization', 'Basic abc')
            ->withHeader('X-Trace', 'keep');

        $stripped = Redirect::withoutSensitiveHeaders($request);

        $this->assertSame('', $stripped->getHeaderLine(Header::AUTHORIZATION));
        $this->assertSame('', $stripped->getHeaderLine(Header::COOKIE));
        $this->assertSame('', $stripped->getHeaderLine('Cookie2'));
        $this->assertSame('', $stripped->getHeaderLine('Proxy-Authorization'));
        $this->assertSame('keep', $stripped->getHeaderLine('X-Trace'));
    }

    public function testItStripsSensitiveHeadersOnHttpsToHttpEvenOnTheSameHost(): void
    {
        $from = Uri::parse('https://example.test/start');
        $to = Redirect::resolve($from, 'http://example.test/final');

        $this->assertTrue(Redirect::shouldStripSensitiveHeaders($from, $to));
        $this->assertFalse(Redirect::isSameOrigin($from, $to));
    }
}
