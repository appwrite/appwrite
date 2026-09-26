<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\Cloudflare;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

final class CloudflareTest extends TestCase
{
    public function testCreatesCustomHostname(): void
    {
        $client = new TestClient([new Response(201, body: new Stream('{"success":true,"result":{"id":"host_1"}}'))]);
        $provider = new Cloudflare('zone', 'token', $client);
        $this->assertNull($provider->issueCertificate('ignored', 'example.com', null));
        $this->assertSame('example.com', $client->calls[0]['body']['hostname']);
        $this->assertSame('http', $client->calls[0]['body']['ssl']['method']);
    }

    public function testDuplicateHostnameIsIdempotent(): void
    {
        $provider = new Cloudflare('zone', 'token', new TestClient([new Response(409, body: new Stream('{"success":false,"errors":[{"code":1406,"message":"duplicate"}]}'))]));
        $this->assertNull($provider->issueCertificate('ignored', 'example.com', null));
    }

    public function testLookupExactMatchAndDelete(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"success":true,"result":[{"id":"wrong","hostname":"other.com"},{"id":"right","hostname":"example.com"}]}')),
            new Response(204),
        ]);
        $provider = new Cloudflare('zone', 'token', $client);
        $provider->deleteCertificate('example.com');
        $this->assertStringEndsWith('/custom_hostnames/right', $client->calls[1]['url']);
    }

    public function testRenewalAndUnsupportedStatus(): void
    {
        $provider = new Cloudflare('zone', 'token', new TestClient([new Response(200, body: new Stream('{"success":true,"result":[]}'))]));
        $this->assertTrue($provider->isRenewRequired('example.com', null));

        $this->expectException(UnsupportedOperation::class);
        $provider->getCertificateStatus('example.com', null);
    }

    public function testRejectsMalformedLookup(): void
    {
        $provider = new Cloudflare('zone', 'token', new TestClient([new Response(200, body: new Stream('invalid'))]));
        $this->expectException(\RuntimeException::class);
        $provider->isRenewRequired('example.com', null);
    }
}
