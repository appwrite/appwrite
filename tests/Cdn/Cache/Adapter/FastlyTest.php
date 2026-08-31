<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\Fastly;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

final class FastlyTest extends TestCase
{
    public function testPurgesPathsAndKeys(): void
    {
        $client = new TestClient(array_fill(0, 2, new Response(200, body: new Stream('{"status":"ok"}'))));
        $cdn = new Fastly('token', 'domain-', 'service-id', true, $client);

        $cdn->purgePaths('example.com', ['/hello world?x=1']);
        $cdn->purgeKeys(['key']);

        $this->assertSame('https://api.fastly.com/purge/example.com/hello%20world?x=1', $client->calls[0]['url']);
        $this->assertSame('https://api.fastly.com/service/service-id/purge', $client->calls[1]['url']);
        $this->assertSame(['surrogate_keys' => ['key']], $client->calls[1]['body']);
        $this->assertSame('1', $client->headers['fastly-soft-purge'] ?? null);
    }

    public function testDomainPurgeTargetsOnlyThatDomainsKey(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"status":"ok"}'))]);

        new Fastly('token', 'domain-', 'shared-service', client: $client)->purgeDomain('example.com');

        // Fastly has no host purge, so a domain is addressed by the surrogate key
        // the origin attaches. Every other domain on the shared service keeps its
        // cached responses.
        $this->assertSame('https://api.fastly.com/service/shared-service/purge', $client->calls[0]['url']);
        $this->assertSame(['surrogate_keys' => ['domain-example.com']], $client->calls[0]['body']);
        $this->assertCount(1, $client->calls);
    }

    public function testDomainPurgeCanUseTheBareHostnameAsTheKey(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"status":"ok"}'))]);

        new Fastly('token', '', 'service-id', client: $client)->purgeDomain('example.com');

        $this->assertSame(['surrogate_keys' => ['example.com']], $client->calls[0]['body']);
    }

    public function testKeysAreSentUnencoded(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"status":"ok"}'))]);

        new Fastly('token', 'domain-', 'service-id', client: $client)->purgeKeys(['domain-example.com-summer sale']);

        // A key is a JSON value, not a path segment, so percent-encoding it would
        // purge a key the origin never attached.
        $this->assertSame(['surrogate_keys' => ['domain-example.com-summer sale']], $client->calls[0]['body']);
    }

    public function testKeysArePurgedInBatches(): void
    {
        $client = new TestClient(array_fill(0, 2, new Response(200, body: new Stream('{"status":"ok"}'))));
        $keys = array_map(static fn(int $i): string => 'key-' . $i, range(1, Fastly::KEYS_PER_PURGE + 1));

        new Fastly('token', 'domain-', 'service-id', client: $client)->purgeKeys($keys);

        // 257 keys is two requests, not 257.
        $this->assertCount(2, $client->calls);
        $this->assertCount(Fastly::KEYS_PER_PURGE, $client->calls[0]['body']['surrogate_keys']);
        $this->assertSame(['key-257'], $client->calls[1]['body']['surrogate_keys']);
    }

    public function testZonePurgeIsItsOwnOperation(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"status":"ok"}'))]);

        new Fastly('token', 'domain-', 'service-id', client: $client)->purgeZone();

        $this->assertSame('https://api.fastly.com/service/service-id/purge_all', $client->calls[0]['url']);
    }


    public function testZonePurgeRequiresServiceId(): void
    {
        $this->expectException(UnsupportedOperation::class);
        new Fastly('token', 'domain-', null, false, new TestClient([]))->purgeZone();
    }

    public function testKeyPurgeRequiresServiceId(): void
    {
        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('service ID');
        new Fastly('token', 'domain-', null, false, new TestClient([]))->purgeKeys(['key']);
    }

    public function testDomainPurgeRequiresServiceId(): void
    {
        $this->expectException(UnsupportedOperation::class);
        new Fastly('token', 'domain-', null, false, new TestClient([]))->purgeDomain('example.com');
    }

    public function testPathPurgeWorksWithoutServiceId(): void
    {
        $client = new TestClient([new Response(200, body: new Stream('{"status":"ok"}'))]);

        new Fastly('token', 'domain-', null, false, $client)->purgePaths('example.com', ['/a.png']);

        $this->assertSame('https://api.fastly.com/purge/example.com/a.png', $client->calls[0]['url']);
    }

}
