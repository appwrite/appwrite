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

final class RequestTest extends TestCase
{
    protected ?Request $request = null;

    public function setUp(): void
    {
        $this->request = new Request(new SwooleRequest());
    }

    public function testGetIPIgnoresClientForwardedForWhenProxiesEmpty(): void
    {
        $previous = getenv('_APP_TRUSTED_PROXIES');
        putenv('_APP_TRUSTED_PROXIES=');

        try {
            $swoole = new SwooleRequest();
            $swoole->server = ['remote_addr' => '203.0.113.10'];
            $swoole->header = ['x-forwarded-for' => '198.51.100.1'];
            $request = new Request($swoole);

            $this->assertSame('203.0.113.10', $request->getIP());
        } finally {
            putenv($previous === false ? '_APP_TRUSTED_PROXIES' : '_APP_TRUSTED_PROXIES=' . $previous);
        }
    }

    #[DataProvider('forwardedForCases')]
    public function testGetIPHonorsForwardedForFromTrustedProxy(string $forwardedFor, string $expected): void
    {
        $previous = getenv('_APP_TRUSTED_PROXIES');
        putenv('_APP_TRUSTED_PROXIES=10.0.0.0/8');

        try {
            $swoole = new SwooleRequest();
            $swoole->server = ['remote_addr' => '10.0.0.2'];
            $swoole->header = ['x-forwarded-for' => $forwardedFor];
            $request = new Request($swoole);

            $this->assertSame($expected, $request->getIP());
        } finally {
            putenv($previous === false ? '_APP_TRUSTED_PROXIES' : '_APP_TRUSTED_PROXIES=' . $previous);
        }
    }

    /**
     * @return \Iterator<string, array{0: string, 1: string}>
     */
    public static function forwardedForCases(): \Iterator
    {
        yield 'single client' => ['198.51.100.1', '198.51.100.1'];
        yield 'appended chain ignores spoofed leftmost' => ['1.2.3.4, 198.51.100.1, 10.0.0.3', '198.51.100.1'];
        yield 'invalid entries skipped' => ['not-an-ip, 198.51.100.1', '198.51.100.1'];
        yield 'all trusted falls back to leftmost' => ['10.0.0.5, 10.0.0.3', '10.0.0.5'];
        yield 'no valid entry uses connection' => ['not-an-ip', '10.0.0.2'];
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

    /**
     * @param array{0: ?string, 1: ?int, 2: ?int} $expected
     */
    #[DataProvider('ranges')]
    public function testParseRange(string $header, array $expected): void
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['range' => $header];
        $request = new Request($swoole);

        $this->assertSame($expected[0], $request->getRangeUnit());
        $this->assertSame($expected[1], $request->getRangeStart());
        $this->assertSame($expected[2], $request->getRangeEnd());
    }

    /**
     * @return \Iterator<string, array{string, array{(string | null), (int | null), (int | null)}}>
     */
    public static function ranges(): \Iterator
    {
        // RFC 9110 bounds are inclusive, so start === end asks for one byte.
        yield 'first byte' => ['bytes=0-0', ['bytes', 0, 0]];
        yield 'last byte' => ['bytes=511-511', ['bytes', 511, 511]];
        yield 'closed' => ['bytes=100-199', ['bytes', 100, 199]];
        yield 'open ended' => ['bytes=100-', ['bytes', 100, null]];
        yield 'other unit' => ['items=0-1', ['items', 0, 1]];
        yield 'reversed' => ['bytes=200-100', [null, null, null]];
        yield 'suffix' => ['bytes=-500', [null, null, null]];
        yield 'multiple' => ['bytes=0-99,200-299', [null, null, null]];
        yield 'not a number' => ['bytes=a-b', [null, null, null]];
        yield 'no bounds' => ['bytes=', [null, null, null]];
        yield 'no unit' => ['=0-99', [null, null, null]];
        yield 'empty' => ['', [null, null, null]];
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
}
