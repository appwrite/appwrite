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

    protected function tearDown(): void
    {
        Method::$processed = [];
        Method::$errors = [];
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

    public function testGetHostnameIgnoresForwardedHostFromUntrustedClient(): void
    {
        $previous = getenv('_APP_TRUSTED_PROXIES');
        putenv('_APP_TRUSTED_PROXIES=10.0.0.0/8');

        try {
            $swoole = new SwooleRequest();
            $swoole->server = ['remote_addr' => '203.0.113.10'];
            $swoole->header = ['host' => 'api.example.com', 'x-forwarded-host' => 'other.example.com:8443', 'x-forwarded-port' => '8443'];
            $request = new Request($swoole);

            $this->assertSame('api.example.com', $request->getHostname());
            $this->assertSame('', $request->getPort());
        } finally {
            putenv($previous === false ? '_APP_TRUSTED_PROXIES' : '_APP_TRUSTED_PROXIES=' . $previous);
        }
    }

    public function testGetHostnameHonorsForwardedHostFromTrustedProxy(): void
    {
        $previous = getenv('_APP_TRUSTED_PROXIES');
        putenv('_APP_TRUSTED_PROXIES=10.0.0.0/8');

        try {
            $swoole = new SwooleRequest();
            $swoole->server = ['remote_addr' => '10.0.0.2'];
            $swoole->header = ['host' => 'api.example.com', 'x-forwarded-host' => 'other.example.com', 'x-forwarded-port' => '8443'];
            $request = new Request($swoole);

            $this->assertSame('other.example.com', $request->getHostname());
            $this->assertSame('8443', $request->getPort());
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

    #[DataProvider('acceptedTypeCases')]
    public function testGetAcceptedType(string $accept, ?string $expected): void
    {
        $this->request->addHeader('accept', $accept);

        $this->assertSame($expected, $this->request->getAcceptedType(['image/avif', 'image/webp']));
    }

    public static function acceptedTypeCases(): \Iterator
    {
        yield 'browser with avif' => ['image/avif,image/webp,image/apng,image/*,*/*;q=0.8', 'image/avif'];
        yield 'server preference wins over header order' => ['image/webp, image/avif', 'image/avif'];
        yield 'webp only' => ['image/webp,*/*', 'image/webp'];
        yield 'refused with zero quality' => ['image/avif;q=0, image/webp;q=0.5', 'image/webp'];
        yield 'case and spacing' => [' IMAGE/AVIF ; Q=1 ', 'image/avif'];
        yield 'wildcards do not count' => ['image/*,*/*', null];
        yield 'empty' => ['', null];
    }

    public function testGetNegotiatedParamsResolvesAuto(): void
    {
        $this->setupNegotiatedRoute();
        $this->request->addHeader('accept', 'image/webp,*/*');
        $this->request->setQueryString(['output' => 'auto']);

        $this->assertSame(['output' => 'webp'], $this->request->getNegotiatedParams());
    }

    public function testGetNegotiatedParamsFallsBackToEmpty(): void
    {
        $this->setupNegotiatedRoute();
        $this->request->addHeader('accept', '*/*');
        $this->request->setQueryString(['output' => 'auto']);

        $this->assertSame(['output' => ''], $this->request->getNegotiatedParams());
    }

    public function testGetNegotiatedParamsIgnoresExplicitValues(): void
    {
        $this->setupNegotiatedRoute();
        $this->request->addHeader('accept', 'image/avif');
        $this->request->setQueryString(['output' => 'png']);

        $this->assertSame([], $this->request->getNegotiatedParams());
    }

    public function testCacheIdentifierKeysAutoByNegotiatedValue(): void
    {
        $avif = $this->negotiatedRequest('image/avif,image/webp', ['output' => 'auto', 'width' => '100']);
        $webp = $this->negotiatedRequest('image/webp', ['output' => 'auto', 'width' => '100']);
        $none = $this->negotiatedRequest('*/*', ['output' => 'auto', 'width' => '100']);

        $this->assertNotSame($avif->cacheIdentifier(), $webp->cacheIdentifier());
        $this->assertSame($avif->cacheIdentifier(), $this->negotiatedRequest('*/*', ['output' => 'avif', 'width' => '100'])->cacheIdentifier());
        $this->assertSame($webp->cacheIdentifier(), $this->negotiatedRequest('image/avif', ['output' => 'webp', 'width' => '100'])->cacheIdentifier());
        $this->assertSame($none->cacheIdentifier(), $this->negotiatedRequest('image/avif', ['width' => '100'])->cacheIdentifier());
    }

    public function testCacheIdentifierIgnoresAcceptForExplicitValues(): void
    {
        $first = $this->negotiatedRequest('image/avif', ['output' => 'png']);
        $second = $this->negotiatedRequest('*/*', ['output' => 'png']);

        $this->assertSame($first->cacheIdentifier(), $second->cacheIdentifier());
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

    /**
     * @param array<string, string> $query
     */
    private function negotiatedRequest(string $accept, array $query): Request
    {
        $swoole = new SwooleRequest();
        $swoole->header = ['accept' => $accept];
        $request = new Request($swoole);
        $request->setQueryString($query);
        $request->setRoute($this->negotiatedRoute());

        return $request;
    }

    private function setupNegotiatedRoute(): void
    {
        $this->request->setRoute($this->negotiatedRoute());
    }

    private function negotiatedRoute(): Route
    {
        $route = new Route(Request::METHOD_GET, '/preview');
        $route->label('cache.params', ['output', 'width']);
        $route->label('cache.negotiate', ['output' => ['avif' => 'image/avif', 'webp' => 'image/webp']]);

        return $route;
    }
}
