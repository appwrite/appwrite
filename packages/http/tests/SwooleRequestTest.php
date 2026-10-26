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
    private string $target = '';

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

    public function testIgnoresTheForwardedHostByDefault(): void
    {
        $request = new Request($this->schemeRequest(['X-Forwarded-Host' => 'example.com:8443', 'X-Forwarded-Port' => '8443']));

        $this->assertSame('localhost', $request->getHostname(), 'an untrusted forwarded host must not replace the Host header');
        $this->assertSame('', $request->getPort(), 'an untrusted forwarded port must not decide the port');
    }

    public function testTrustsANamedForwardedHost(): void
    {
        $request = new Request(
            $this->schemeRequest(['X-Forwarded-Host' => 'spoofed.example:9000, Example.com:8443']),
            new TrustedHeaders(host: ['x-forwarded-host']),
        );

        $this->assertSame('example.com', $request->getHostname());
        $this->assertSame('8443', $request->getPort());
    }

    public function testTrustsANamedForwardedPort(): void
    {
        $request = new Request(
            $this->schemeRequest(['X-Forwarded-Port' => '8443']),
            new TrustedHeaders(port: ['x-forwarded-port']),
        );

        $this->assertSame('8443', $request->getPort());
    }

    public function testSkipsATrustedPortHeaderThatIsNotANumber(): void
    {
        $request = new Request(
            $this->schemeRequest(['X-Forwarded-Port' => 'not-a-port']),
            new TrustedHeaders(port: ['x-forwarded-port']),
        );

        $this->assertSame('', $request->getPort());
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

    public function testSizeCountsTheJSONBody(): void
    {
        $body = \json_encode(['data' => \str_repeat('a', 100_000)], JSON_THROW_ON_ERROR);

        $request = new Request($this->swooleRequest($body));

        $this->assertGreaterThanOrEqual(\strlen($body), $request->getSize(), 'the request size must include the body, not only the headers');
    }

    public function testSizeCountsTheMultipartBody(): void
    {
        $boundary = 'appwrite-boundary';
        $file = \str_repeat('b', 100_000);
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"fileId\"\r\n\r\n"
            . "unique()\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"file.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $file . "\r\n"
            . "--{$boundary}--\r\n";

        $request = new Request($this->swooleRequest($body, 'multipart/form-data; boundary=' . $boundary));

        $this->assertGreaterThanOrEqual(\strlen($body), $request->getSize(), 'the request size must include the uploaded file');
    }

    /**
     * A client-supplied `Swoole-Upload-File` part header makes Swoole report any
     * server path as a ready upload and unlink it when the request is freed
     * (swoole/swoole-src#6309).
     */
    public function testSpoofedUploadIsNeutralized(): void
    {
        $swoole = $this->multipartRequest($this->filePart('file', "Swoole-Upload-File: {$this->target}"));

        $this->assertSame($this->target, $this->at($swoole->files, 'file', 'tmp_name'), 'Swoole reports the spoofed path as the upload');

        $request = new Request($swoole);
        $file = $request->getFiles('file');

        $this->assertSame('', $file['tmp_name']);
        $this->assertSame(\UPLOAD_ERR_CANT_WRITE, $file['error']);
        $this->assertSame(0, $file['size']);
        $this->assertSame([], $swoole->tmpfiles);

        unset($request, $swoole);

        $this->assertFileExists($this->target);
    }

    public function testSpoofedUploadInsideNestedFieldIsNeutralized(): void
    {
        $swoole = $this->multipartRequest(
            $this->filePart('file[0][part]', "swoole-UPLOAD-file:  {$this->target}"),
            $this->filePart('file[0][other]'),
            $this->filePart('empty', filename: '', content: ''),
        );
        $request = new Request($swoole);
        $file = $request->getFiles('file');

        $this->assertSame('', $this->at($file, 'tmp_name', 0, 'part'));
        $this->assertSame(\UPLOAD_ERR_CANT_WRITE, $this->at($file, 'error', 0, 'part'));
        $this->assertSame(0, $this->at($file, 'size', 0, 'part'));
        $this->assertSame([$this->at($file, 'tmp_name', 0, 'other')], $swoole->tmpfiles, 'the genuine sibling stays an upload');
        $this->assertSame(\UPLOAD_ERR_NO_FILE, $request->getFiles('empty')['error'], 'a failed upload keeps its own error');
    }

    public function testUploadMentioningTheMarkerStaysIntact(): void
    {
        $content = "notes\nSwoole-Upload-File: {$this->target}\n";
        $swoole = $this->multipartRequest($this->filePart('file', content: $content));
        $file = new Request($swoole)->getFiles('file');

        $this->assertIsString($file['tmp_name']);
        $this->assertStringStartsWith('swoole.upfile.', \basename($file['tmp_name']));
        $this->assertSame(\strlen($content), $file['size']);
        $this->assertSame([$file['tmp_name']], $swoole->tmpfiles);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->target = \sys_get_temp_dir() . '/target-' . \bin2hex(\random_bytes(4));
        \file_put_contents($this->target, 'secret');
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (\is_file($this->target)) {
            \unlink($this->target);
        }
    }

    private function filePart(string $name, string $header = '', string $filename = 'upload.txt', string $content = 'payload'): string
    {
        return "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
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
        \assert($request instanceof SwooleRequest);

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

    private function at(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (! \is_array($value)) {
                $this->fail('expected an array at ' . $key);
            }

            $value = $value[$key] ?? null;
        }

        return $value;
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
