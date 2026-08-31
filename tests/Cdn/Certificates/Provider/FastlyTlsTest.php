<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Status;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

final class FastlyTlsTest extends TestCase
{
    public function testIssueCertificateCreatesSubscriptionWhenMissing(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[]}')),
            new Response(200, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"pending"}}}')),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $renewDate = $provider->issueCertificate('ignored', 'example.com', null);

        $this->assertNull($renewDate);
        $this->assertCount(2, $client->calls);
        $this->assertSame('GET', $client->calls[0]['method']);
        $this->assertStringContainsString('filter%5Btls_domains.id%5D=example.com', $client->calls[0]['url']);
        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame('tls-config-id', $client->calls[1]['body']['data']['relationships']['tls_configuration']['data']['id']);
    }

    public function testIssueCertificateCanUseFastlyDomainManagementWithoutAConfiguration(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[]}')),
            new Response(201, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"pending"}}}')),
        ]);

        new FastlyTls('token', '', 'certainly', $client)->issueCertificate('cert', 'example.com', null);

        $relationships = $client->calls[1]['body']['data']['relationships'];
        $this->assertSame('example.com', $relationships['tls_domains']['data'][0]['id']);
        $this->assertArrayNotHasKey('common_name', $relationships);
        $this->assertArrayNotHasKey('tls_configuration', $relationships);
    }

    public function testGetCertificateStatusMapsFastlyState(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}')),
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}')),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);

        $this->assertSame(Status::ISSUED, $provider->getCertificateStatus('example.com', null));
        $this->assertFalse($provider->isRenewRequired('example.com', null));
    }

    public function testDeleteCertificateRemovesSubscription(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}')),
            new Response(204),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $provider->deleteCertificate('example.com');

        $this->assertCount(2, $client->calls);
        $this->assertSame('DELETE', $client->calls[1]['method']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_123?force=true', $client->calls[1]['url']);
    }

    public function testIssueCertificateReturnsRenewDateFromIncludedCertificate(): void
    {
        $client = new TestClient([new Response(200, body: new Stream(json_encode([
            'data' => [[
                'id' => 'sub_123',
                'attributes' => ['state' => 'issued'],
                'relationships' => ['tls_certificates' => ['data' => [['type' => 'tls_certificate', 'id' => 'cert_1']]]],
            ]],
            'included' => [[
                'type' => 'tls_certificate',
                'id' => 'cert_1',
                'attributes' => ['not_after' => '2027-02-01T00:00:00Z'],
            ]],
        ])))]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $this->assertSame('2027-01-02 00:00:00.000', $provider->issueCertificate('cert', 'example.com', null));
    }

    public function testRetriesFailedSubscription(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"failed"}}]}')),
            new Response(200, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"processing"}}}')),
        ]);
        $provider = new FastlyTls('token', 'config', 'certainly', $client);
        $this->assertNull($provider->issueCertificate('cert', 'example.com', null));
        $this->assertSame('PATCH', $client->calls[1]['method']);
    }

    public function testRejectsMalformedSuccessfulResponse(): void
    {
        $provider = new FastlyTls('token', 'config', 'certainly', new TestClient([new Response(200, body: new Stream('not-json'))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('valid JSON');
        $provider->getCertificateStatus('example.com', null);
    }
}
