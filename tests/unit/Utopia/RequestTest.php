<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia;

use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Request\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Tests\Unit\Utopia\Request\Filters\First;
use Tests\Unit\Utopia\Request\Filters\Second;
use Tests\Unit\Utopia\Request\Filters\ThrowingFilter;
use Utopia\Http\Route;
use Utopia\System\System;

final class RequestTest extends TestCase
{
    protected ?Request $request = null;

    public function setUp(): void
    {
        $this->request = new Request(new SwooleRequest());
    }

    public function testFilters(): void
    {
        $this->assertFalse($this->request->hasFilters());
        $this->assertEmpty($this->request->getFilters());

        $this->request->addFilter(new First());
        $this->request->addFilter(new Second());

        $this->assertTrue($this->request->hasFilters());
        $this->assertCount(2, $this->request->getFilters());

        $route = new Route(Request::METHOD_GET, '/test');
        $route->label('sdk', new Method(
            namespace: 'namespace',
            group: 'group',
            name: 'method',
            description: 'description',
            auth: [],
            responses: [],
        ));
        // set test header to prevent header populaten inside the request class
        $this->request->addHeader('EXAMPLE', 'VALUE');
        $this->request->setRoute($route);
        $this->request->setQueryString([
            'initial' => true,
            'first' => false
        ]);
        $output = $this->request->getParams();

        $this->assertArrayHasKey('initial', $output);
        $this->assertTrue($output['initial']);
        $this->assertArrayHasKey('first', $output);
        $this->assertTrue($output['first']);
        $this->assertArrayHasKey('second', $output);
        $this->assertTrue($output['second']);
        $this->assertArrayNotHasKey('deleted', $output);
    }

    public function testGetParamsWithMultipleMethods(): void
    {
        $this->setupMultiMethodRoute();

        // Pass only "foo", should match Method A
        $this->request->setQueryString([
            'foo' => 'valueFoo',
        ]);

        $params = $this->request->getParams();

        $this->assertArrayHasKey('foo', $params);
        $this->assertSame('valueFoo', $params['foo']);
        $this->assertArrayNotHasKey('baz', $params);
    }

    public function testGetParamsWithAllRequired(): void
    {
        $this->setupMultiMethodRoute();

        // Pass "foo" and "bar", should match Method A
        $this->request->setQueryString([
            'foo' => 'valueFoo',
            'bar' => 'valueBar',
        ]);

        $params = $this->request->getParams();
        $this->assertArrayHasKey('foo', $params);
        $this->assertSame('valueFoo', $params['foo']);
        $this->assertArrayHasKey('bar', $params);
        $this->assertSame('valueBar', $params['bar']);
        $this->assertArrayNotHasKey('baz', $params);
    }

    public function testGetParamsWithAllOptional(): void
    {
        $this->setupMultiMethodRoute();

        // Pass only "bar", should match Method A
        $this->request->setQueryString([
            'bar' => 'valueBar',
        ]);

        $params = $this->request->getParams();

        $this->assertArrayHasKey('bar', $params);
        $this->assertSame('valueBar', $params['bar']);
        $this->assertArrayNotHasKey('foo', $params);
        $this->assertArrayNotHasKey('baz', $params);
    }

    public function testGetParamsMatchesMethodB(): void
    {
        $this->setupMultiMethodRoute();

        // Pass only "baz", should match Method B
        $this->request->setQueryString([
            'baz' => 'valueBaz',
        ]);

        $params = $this->request->getParams();

        $this->assertArrayHasKey('baz', $params);
        $this->assertSame('valueBaz', $params['baz']);
        $this->assertArrayNotHasKey('foo', $params);
    }

    public function testGetParamsFallbackForMixedAndUnknown(): void
    {
        $this->setupMultiMethodRoute();

        // Mixed and unknown should fallback to raw params
        $this->request->setQueryString([
            'foo' => 'valueFoo',
            'baz' => 'valueBaz',
            'extra' => 'unexpected',
        ]);

        $params = $this->request->getParams();

        $this->assertArrayHasKey('foo', $params);
        $this->assertSame('valueFoo', $params['foo']);
        $this->assertArrayHasKey('baz', $params);
        $this->assertSame('valueBaz', $params['baz']);
        $this->assertArrayHasKey('extra', $params);
        $this->assertSame('unexpected', $params['extra']);
    }

    public function testRouteIsScopedToRequestInstance(): void
    {
        $firstRequest = new Request(new SwooleRequest());
        $secondRequest = new Request(new SwooleRequest());

        $firstRoute = new Route(Request::METHOD_GET, '/first');
        $secondRoute = new Route(Request::METHOD_GET, '/second');

        $firstRequest->setRoute($firstRoute);
        $secondRequest->setRoute($secondRoute);

        $this->assertSame($firstRoute, $firstRequest->getRoute());
        $this->assertSame($secondRoute, $secondRequest->getRoute());
    }

    public function testGetHeaderLineReturnsStringValue(): void
    {
        $this->request->addHeader('referer', 'https://example.com');

        $this->assertSame('https://example.com', $this->request->getHeaderLine('referer'));
    }

    public function testGetHeaderLineReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('', $this->request->getHeaderLine('referer'));
        $this->assertSame('fallback', $this->request->getHeaderLine('referer', 'fallback'));
    }

    public function testGetHeaderLineJoinsMultipleValues(): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['referer' => ['https://a.example', 'https://b.example']];
        $request = new Request($swoole);

        $this->assertSame('https://a.example, https://b.example', $request->getHeaderLine('referer'));
    }

    public function testGetHeadersSynthesizesCookieHeaderFromCookieJar(): void
    {
        // Swoole parses the Cookie header into its cookie jar, so getHeaders()
        // must rebuild it for consumers that forward request headers onward
        // (e.g. function/site executions).
        $swoole = new SwooleRequest();
        $swoole->header = ['host' => 'example.com'];
        $swoole->cookie = ['custom-session-id' => 'abcd123', 'custom-user-id' => 'efgh456'];
        $request = new Request($swoole);

        $headers = $request->getHeaders();

        $this->assertSame(['custom-session-id=abcd123; custom-user-id=efgh456'], $headers['cookie']);
    }

    public function testGetHeadersOmitsCookieHeaderWhenNoCookies(): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['host' => 'example.com'];
        $request = new Request($swoole);

        $this->assertArrayNotHasKey('cookie', $request->getHeaders());
    }

    public function testGetHeadersWithoutCookiesLeavesNamedCookiesOut(): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['host' => 'example.com'];
        $swoole->cookie = ['a_jwt_console' => 'jwt', 'theme' => 'dark', 'a_session_console' => 'secret'];
        $request = new Request($swoole);

        $headers = $request->getHeadersWithoutCookies(['a_jwt_console', 'a_session_console']);

        $this->assertSame(['theme=dark'], $headers['cookie']);
        $this->assertSame(['example.com'], $headers['host']);
    }

    public function testGetHeadersWithoutCookiesOmitsCookieHeaderWhenAllLeftOut(): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['host' => 'example.com', 'cookie' => 'a_jwt_console=jwt'];
        $swoole->cookie = ['a_jwt_console' => 'jwt'];
        $request = new Request($swoole);

        $this->assertArrayNotHasKey('cookie', $request->getHeadersWithoutCookies(['a_jwt_console']));
    }

    public function testGetParamsCachesRawParamsWhenFilterThrows4xx(): void
    {
        /*
        * Regression: when a request filter throws a 4xx exception during
        * Request::getParams() (e.g. RequestV20 rejecting an unparseable
        * queries[]), the framework's error path calls getParams() again to
        * build error-hook arguments. Without caching, that second call
        * re-runs the filter and re-throws, which the framework wraps as
        * "Error handler had an error: ..." (HTTP 500), masking the intended
        * 400. This test pins that behavior: the first call throws (so the
        * action's argument resolution aborts), but the second call returns
        * the raw, pre-filter params without re-invoking filters.
        */
        $filter = new ThrowingFilter(400, 'invalid input');

        $this->setupSingleMethodRoute($filter);
        $this->request->setQueryString(['foo' => 'bar']);

        $threw = false;
        try {
            $this->request->getParams();
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertSame(400, $e->getCode());
            $this->assertSame('invalid input', $e->getMessage());
        }
        $this->assertTrue($threw, 'First getParams() call must rethrow the filter exception.');
        $this->assertSame(1, $filter->calls, 'Filter ran once on the first call.');

        // Second call: framework's error hook arg resolution. Must return raw
        // params without re-invoking the filter.
        $params = $this->request->getParams();
        $this->assertSame(['foo' => 'bar'], $params);
        $this->assertSame(1, $filter->calls, 'Filter must not run again after a cached 4xx failure.');
    }

    public function testGetParamsDoesNotCacheRawParamsForServerError(): void
    {
        /*
        * 5xx filter throws indicate genuine server-side problems, not
        * user-input mistakes. They must keep rethrowing on every call so
        * the framework's normal error handling sees the failure each time
        * — caching raw params would silently swallow real bugs.
        */
        $filter = new ThrowingFilter(500, 'boom');

        $this->setupSingleMethodRoute($filter);
        $this->request->setQueryString(['foo' => 'bar']);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $threw = false;
            try {
                $this->request->getParams();
            } catch (\Throwable $e) {
                $threw = true;
                $this->assertSame(500, $e->getCode());
            }
            $this->assertTrue($threw, "Call #$attempt must rethrow.");
            $this->assertSame($attempt, $filter->calls, "Filter must run on call #$attempt.");
        }
    }

    public function testGetParamsDoesNotCacheRawParamsForUncodedException(): void
    {
        // \Exception with the default code of 0 is treated as "unknown" and
        // must propagate every call — same reasoning as 5xx.
        $filter = new ThrowingFilter(0, 'unknown');

        $this->setupSingleMethodRoute($filter);
        $this->request->setQueryString(['foo' => 'bar']);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $threw = false;
            try {
                $this->request->getParams();
            } catch (\Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, "Call #$attempt must rethrow.");
            $this->assertSame($attempt, $filter->calls, "Filter must run on call #$attempt.");
        }
    }

    /**
     * Helper to attach a route with a single SDK method and one filter.
     */
    private function setupSingleMethodRoute(Filter $filter): void
    {
        $route = new Route(Request::METHOD_GET, '/single');
        $route->label('sdk', new Method(
            namespace: 'namespace',
            group: 'group',
            name: 'method',
            description: 'description',
            auth: [],
            responses: [],
        ));

        $this->request->addHeader('EXAMPLE', 'VALUE');
        $this->request->setRoute($route);
        $this->request->addFilter($filter);
    }

    /**
     * Helper to attach a route with multiple SDK methods to the request.
     */
    private function setupMultiMethodRoute(): void
    {
        $route = new Route(Request::METHOD_GET, '/multi');

        $methodA = new Method(
            namespace: 'namespace',
            group: 'group',
            name: 'methodA',
            description: 'desc',
            auth: [],
            responses: [],
            parameters: [
                new Parameter('foo'),
                new Parameter('bar', optional: true),
            ],
        );

        $methodB = new Method(
            namespace: 'namespace',
            group: 'group',
            name: 'methodB',
            description: 'desc',
            auth: [],
            responses: [],
            parameters: [
                new Parameter('baz'),
            ],
        );

        $route->label('sdk', [$methodA, $methodB]);
        $this->request->addFilter(new First());
        $this->request->addFilter(new Second());
        $this->request->setRoute($route);
    }

    public function testGetParamsDropsRequestValuesThatNameACallable(): void
    {
        $this->request->addHeader('EXAMPLE', 'VALUE');
        $this->request->setQueryString([
            'callback' => [System::class, 'getEnv'],
            'name' => 'getenv',
            'list' => ['one', 'two'],
        ]);

        $this->assertSame(['name' => 'getenv', 'list' => ['one', 'two']], $this->request->getParams());
    }

    /**
     * A client-supplied `Swoole-Upload-File` part header makes Swoole report any
     * server path as a ready upload and unlink it when the request is freed
     * (swoole/swoole-src#6309).
     */
    public function testSpoofedUploadIsNeutralized(): void
    {
        $target = $this->target();
        $swoole = $this->multipartRequest($this->filePart('file', "Swoole-Upload-File: {$target}"));

        $this->assertSame($target, $swoole->files['file']['tmp_name'], 'Swoole reports the spoofed path as the upload');

        $request = new Request($swoole);
        $file = $request->getFiles('file');

        $this->assertSame('', $file['tmp_name']);
        $this->assertSame(\UPLOAD_ERR_CANT_WRITE, $file['error']);
        $this->assertSame(0, $file['size']);
        $this->assertSame([], $swoole->tmpfiles);

        unset($request, $swoole);

        $this->assertFileExists($target);
        \unlink($target);
    }

    public function testSpoofedUploadInsideNestedFieldIsNeutralized(): void
    {
        $target = $this->target();
        $swoole = $this->multipartRequest(
            $this->filePart('file[0][part]', "swoole-UPLOAD-file:  {$target}"),
            $this->filePart('file[0][other]'),
        );
        $file = (new Request($swoole))->getFiles('file');

        $this->assertSame('', $file['tmp_name'][0]['part']);
        $this->assertSame(\UPLOAD_ERR_CANT_WRITE, $file['error'][0]['part']);
        $this->assertSame(0, $file['size'][0]['part']);
        $this->assertSame([$file['tmp_name'][0]['other']], $swoole->tmpfiles, 'the genuine sibling stays an upload');

        \unlink($target);
    }

    public function testUploadMentioningTheMarkerStaysIntact(): void
    {
        $target = $this->target();
        $content = "notes\nSwoole-Upload-File: {$target}\n";
        $swoole = $this->multipartRequest($this->filePart('file', content: $content));
        $file = (new Request($swoole))->getFiles('file');

        $this->assertStringStartsWith('swoole.upfile.', \basename($file['tmp_name']));
        $this->assertSame(\strlen($content), $file['size']);
        $this->assertSame([$file['tmp_name']], $swoole->tmpfiles);

        \unlink($target);
    }

    private function target(): string
    {
        $target = \sys_get_temp_dir() . '/target-' . \bin2hex(\random_bytes(4));
        \file_put_contents($target, 'secret');

        return $target;
    }

    private function filePart(string $name, string $header = '', string $content = 'payload'): string
    {
        return "Content-Disposition: form-data; name=\"{$name}\"; filename=\"upload.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . ($header === '' ? '' : "{$header}\r\n")
            . "\r\n"
            . $content;
    }

    /**
     * Parses a multipart request through Swoole's own parser, which writes uploads to disk.
     */
    private function multipartRequest(string ...$parts): SwooleRequest
    {
        $boundary = 'upload-boundary';
        $body = '';

        foreach ($parts as $part) {
            $body .= "--{$boundary}\r\n{$part}\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $request = SwooleRequest::create(['parse_cookie' => false]);
        $request->parse(
            "POST /v1/storage/buckets/files HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "\r\n"
            . $body,
        );

        return $request;
    }

    #[DataProvider('contentRangeCases')]
    public function testGetContentRangeChunk(string $contentRange, ?int $start, int $chunk): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['content-range' => $contentRange];
        $request = new Request($swoole);

        $this->assertSame($start, $request->getContentRangeStart());
        $this->assertSame($chunk, $request->getContentRangeChunk(5242880));
    }

    /**
     * @return \Iterator<string, array{0: string, 1: ?int, 2: int}>
     */
    public static function contentRangeCases(): \Iterator
    {
        yield 'first chunk' => ['bytes 0-5242879/12582912', 0, 0];
        yield 'second chunk' => ['bytes 5242880-10485759/12582912', 5242880, 1];
        yield 'last chunk shorter than the rest' => ['bytes 10485760-12582911/12582912', 10485760, 2];
        yield 'short range inside a chunk' => ['bytes 5242881-5242881/12582912', 5242881, 1];
        yield 'end equal to size' => ['bytes 0-10/10', 0, 0];
        yield 'bounds at the integer limit' => ['bytes 9223372036854775807-9223372036854775807/9223372036854775807', null, 0];
        yield 'bounds past the integer limit' => ['bytes 0-99999999999999999999/99999999999999999999', null, 0];
        yield 'size past the integer limit' => ['bytes 0-9/99999999999999999999', null, 0];
        yield 'start after end' => ['bytes 10-5/20', null, 0];
        yield 'end past size' => ['bytes 0-20/10', null, 0];
        yield 'negative start' => ['bytes -1-5/10', null, 0];
        yield 'missing size' => ['bytes 0-5', null, 0];
        yield 'missing unit' => ['0-5/10', null, 0];
    }
}
