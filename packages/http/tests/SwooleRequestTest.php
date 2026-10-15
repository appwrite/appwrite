<?php

declare(strict_types=1);

namespace Utopia\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\TrustedHeaders;

#[RequiresPhpExtension('swoole')]
final class SwooleRequestTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function bodies(): \Iterator
    {
        yield 'bare empty object' => ['{"data":{}}', '{"data":{}}'];
        yield 'nested empty object' => ['{"data":{"inner":{}}}', '{"data":{"inner":{}}}'];
        yield 'empty object beside a value' => ['{"data":{"a":{},"b":1}}', '{"data":{"a":{},"b":1}}'];
        yield 'empty object two levels down' => ['{"data":{"l1":{"l2":{}}}}', '{"data":{"l1":{"l2":{}}}}'];
        yield 'empty object inside a list' => ['{"data":{"arr":[{},{"x":1}]}}', '{"data":{"arr":[{},{"x":1}]}}'];
        yield 'populated object' => ['{"data":{"a":1}}', '{"data":{"a":1}}'];
        yield 'empty object as the only param' => ['{"data":{},"other":{}}', '{"data":{},"other":{}}'];
        yield 'braces inside a string literal' => ['{"data":{"note":"braces {} in text"}}', '{"data":{"note":"braces {} in text"}}'];
        yield 'whitespace inside the empty object' => ['{"data":{"a":{ }}}', '{"data":{"a":{}}}'];
        yield 'newline inside the empty object' => ["{\"data\":{\"a\":{\n}}}", '{"data":{"a":{}}}'];
        yield 'empty list stays a list' => ['{"data":{"a":[]}}', '{"data":{"a":[]}}'];
    }

    /**
     * A param holding an empty JSON object has to re-encode as the object that was
     * sent. `json_decode($body, true)` returns `[]` for both `{}` and `[]`, so an
     * empty object silently became an empty array in every stored payload.
     */
    #[DataProvider('bodies')]
    public function testJSONBodyRoundTrips(string $body, string $expected): void
    {
        $request = new Request($this->swooleRequest($body));

        $this->assertSame(
            $expected,
            json_encode($request->getParams()),
            'the decoded params must re-encode as the body that was sent',
        );
    }

    public function testPopulatedObjectsStayAssociativeArrays(): void
    {
        $request = new Request($this->swooleRequest('{"data":{"a":{},"b":{"c":1}}}'));

        $data = $request->getPayload('data');

        $this->assertIsArray($data);
        $this->assertInstanceOf(\stdClass::class, $data['a']);
        $this->assertIsArray($data['b'], 'a populated object must stay an associative array');
        $this->assertSame(['c' => 1], $data['b']);
    }

    public function testBodyWithoutParamsDecodesToNoParams(): void
    {
        foreach (['{}', '{ }', '[]', '', 'not json', '"a string"', '5'] as $body) {
            $request = new Request($this->swooleRequest($body));

            $this->assertSame([], $request->getParams(), 'body ' . $body . ' carries no params');
        }
    }

    public function testFormBodyIsUnaffected(): void
    {
        $swoole = $this->swooleRequest('data=value', 'application/x-www-form-urlencoded');

        $this->assertSame(['data' => 'value'], (new Request($swoole))->getParams());
    }

    public function testTrustsTheForwardedProtocolByDefault(): void
    {
        $request = new Request($this->schemeRequest(['X-Forwarded-Proto' => 'https']));

        $this->assertSame('https', $request->getProtocol());
    }

    public function testTakesTheClientSchemeFromAChainOfProxies(): void
    {
        $request = new Request($this->schemeRequest(['X-Forwarded-Proto' => 'https, http']));

        $this->assertSame('https', $request->getProtocol());
    }

    public function testCanTrustAProtocolHeaderOfAnyName(): void
    {
        $request = new Request(
            $this->schemeRequest(['X-CDN-Proto' => 'https']),
            new TrustedHeaders(proto: ['x-cdn-proto']),
        );

        $this->assertSame('https', $request->getProtocol());
    }

    public function testStopsTrustingTheForwardedProtocolWhenAnotherIsNamed(): void
    {
        $request = new Request(
            $this->schemeRequest(['X-Forwarded-Proto' => 'https']),
            new TrustedHeaders(proto: ['x-cdn-proto']),
        );

        $this->assertSame('http', $request->getProtocol(), 'the untrusted header must not decide the scheme');
    }

    public function testFallsBackToTheRequestLineWhenNoTrustedHeaderSaysAnything(): void
    {
        $request = new Request($this->schemeRequest([]));

        $this->assertSame('http', $request->getProtocol());
    }

    public function testSkipsATrustedProtocolHeaderThatNamesNoKnownScheme(): void
    {
        $request = new Request(
            $this->schemeRequest(['X-CDN-Proto' => 'gopher', 'X-Forwarded-Proto' => 'https']),
            new TrustedHeaders(proto: ['x-cdn-proto', 'x-forwarded-proto']),
        );

        $this->assertSame('https', $request->getProtocol());
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function schemeRequest(array $headers): SwooleRequest
    {
        $request = SwooleRequest::create(['parse_cookie' => false, 'parse_files' => false]);
        $raw = "GET /v1/documents HTTP/1.1\r\nHost: localhost\r\n";

        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }

        $request->parse($raw . "\r\n");

        return $request;
    }

    private function swooleRequest(string $body, string $contentType = 'application/json'): SwooleRequest
    {
        $request = SwooleRequest::create(['parse_cookie' => false, 'parse_files' => false]);
        $request->parse(
            "POST /v1/documents HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . 'Content-Type: ' . $contentType . "\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "\r\n"
            . $body,
        );

        return $request;
    }
}
