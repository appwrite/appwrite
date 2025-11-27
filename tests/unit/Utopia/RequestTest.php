<?php

namespace Tests\Unit\Utopia;

use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Tests\Unit\Utopia\Request\Filters\First;
use Tests\Unit\Utopia\Request\Filters\Second;
use Utopia\Route;

class RequestTest extends TestCase
{
    protected ?Request $request = null;

    public function setUp(): void
    {
        $this->request = new Request(new SwooleRequest());
    }

    public function testFilters(): void
    {
        $this->assertFalse($this->request->hasFilters());
        $this->assertIsArray($this->request->getFilters());
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

    public function testGetIPWithDefaultFallback(): void
    {
        // No headers set, should return remote_addr
        $ip = $this->request->getIP();
        
        $this->assertIsString($ip);
        // Default fallback when nothing is set
        $this->assertSame('0.0.0.0', $ip);
    }

    public function testGetIPWithRemoteAddr(): void
    {
        // Set remote_addr in server variables
        $this->request->setServer('remote_addr', '192.168.1.100');
        
        $ip = $this->request->getIP();
        
        $this->assertSame('192.168.1.100', $ip);
    }

    public function testGetIPWithXForwardedFor(): void
    {
        // Set X-Forwarded-For header with single IP
        $this->request->addHeader('x-forwarded-for', '203.0.113.195');
        
        $ip = $this->request->getIP();
        
        $this->assertSame('203.0.113.195', $ip);
    }

    public function testGetIPWithMultipleProxies(): void
    {
        // Set X-Forwarded-For with multiple IPs (leftmost is client)
        $this->request->addHeader('x-forwarded-for', '203.0.113.195, 70.41.3.18, 150.172.238.178');
        
        $ip = $this->request->getIP();
        
        // Should return the leftmost (original client) IP
        $this->assertSame('203.0.113.195', $ip);
    }

    public function testGetIPWithWhitespaceInHeader(): void
    {
        // Test that whitespace is properly trimmed
        $this->request->addHeader('x-forwarded-for', '  203.0.113.195  ,  70.41.3.18  ');
        
        $ip = $this->request->getIP();
        
        $this->assertSame('203.0.113.195', $ip);
    }

    public function testGetIPWithInvalidIP(): void
    {
        // Set invalid IP in X-Forwarded-For
        // When the leftmost IP is invalid, the entire header is skipped
        // and we fallback to remote_addr (intentional security behavior)
        $this->request->addHeader('x-forwarded-for', 'not-an-ip, 203.0.113.195');
        $this->request->setServer('remote_addr', '192.168.1.100');
        
        $ip = $this->request->getIP();
        
        // Should fallback to remote_addr since leftmost IP is invalid
        $this->assertSame('192.168.1.100', $ip);
    }

    public function testGetIPWithCustomTrustedHeader(): void
    {
        // Assuming you can set environment variable in test
        $_ENV['_APP_TRUSTED_HEADERS'] = 'cf-connecting-ip';
        
        $this->request->addHeader('cf-connecting-ip', '203.0.113.195');
        $this->request->addHeader('x-forwarded-for', '198.51.100.178');
        
        $ip = $this->request->getIP();
        
        // Should use cf-connecting-ip since it's the trusted header
        $this->assertSame('203.0.113.195', $ip);
        
        unset($_ENV['_APP_TRUSTED_HEADERS']);
    }

    public function testGetIPWithMultipleTrustedHeaders(): void
    {
        $_ENV['_APP_TRUSTED_HEADERS'] = 'cf-connecting-ip, x-real-ip, x-forwarded-for';
        
        // Only set the third header
        $this->request->addHeader('x-forwarded-for', '203.0.113.195');
        
        $ip = $this->request->getIP();
        
        $this->assertSame('203.0.113.195', $ip);
        
        unset($_ENV['_APP_TRUSTED_HEADERS']);
    }

    public function testGetIPHeaderPriority(): void
    {
        $_ENV['_APP_TRUSTED_HEADERS'] = 'cf-connecting-ip, x-forwarded-for';
        
        // Set both headers, cf-connecting-ip should take priority
        $this->request->addHeader('cf-connecting-ip', '203.0.113.195');
        $this->request->addHeader('x-forwarded-for', '198.51.100.178');
        
        $ip = $this->request->getIP();
        
        // Should return the first trusted header's value
        $this->assertSame('203.0.113.195', $ip);
        
        unset($_ENV['_APP_TRUSTED_HEADERS']);
    }

    public function testGetIPWithIPv6(): void
    {
        $this->request->addHeader('x-forwarded-for', '2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        
        $ip = $this->request->getIP();
        
        $this->assertSame('2001:0db8:85a3:0000:0000:8a2e:0370:7334', $ip);
    }

    public function testGetIPWithEmptyHeader(): void
    {
        $this->request->addHeader('x-forwarded-for', '');
        $this->request->setServer('remote_addr', '192.168.1.100');
        
        $ip = $this->request->getIP();
        
        // Should fallback to remote_addr when header is empty
        $this->assertSame('192.168.1.100', $ip);
    }

    public function testGetIPWithEmptyTrustedHeadersConfig(): void
    {
        $_ENV['_APP_TRUSTED_HEADERS'] = ' , , ';
        $this->request->setServer('remote_addr', '192.168.1.100');
        
        $ip = $this->request->getIP();
        
        // Should fallback to remote_addr when config is effectively empty
        $this->assertSame('192.168.1.100', $ip);
        
        unset($_ENV['_APP_TRUSTED_HEADERS']);
    }
}
