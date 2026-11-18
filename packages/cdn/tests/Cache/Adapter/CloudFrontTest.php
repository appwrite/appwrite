<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests\Cache\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Cache\Adapter\CloudFront;
use Utopia\Cdn\Exception\UnsupportedOperation;
use Utopia\Cdn\Tests\TestClient;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class CloudFrontTest extends TestCase
{
    private const string API = 'https://cloudfront.amazonaws.com/2020-05-31';

    public function testPurgesPathsOnTheDomainsTenant(): void
    {
        $client = new TestClient([$this->tenant('dt_example'), $this->created()]);

        $this->adapter($client)->purgePaths('example.com', ['/hello world', '/assets/*']);

        $this->assertSame('GET', $client->calls[0]['method']);
        $this->assertSame(self::API . '/distribution-tenant?domain=example.com', $client->calls[0]['url']);
        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame(self::API . '/distribution-tenant/dt_example/invalidation', $client->calls[1]['url']);
        // Unsafe characters are encoded; a trailing "*" stays a wildcard.
        $this->assertSame(['/hello%20world', '/assets/*'], $this->paths($client, 1));
    }

    public function testLiteralPercentSignsAreEncodedButEscapesAreKept(): void
    {
        $client = new TestClient([$this->tenant('dt_example'), $this->created()]);

        $this->adapter($client)->purgePaths('example.com', ['/files/100%.png', '/caf%C3%A9', '/%4']);

        $this->assertSame(['/files/100%25.png', '/caf%C3%A9', '/%254'], $this->paths($client, 1));
    }

    public function testPathPurgeRefusesDotSegmentsBeforeSending(): void
    {
        $client = new TestClient([]);

        try {
            $this->adapter($client)->purgePaths('example.com', ['/../x']);
            $this->fail('A path with dot segments must be refused');
        } catch (\InvalidArgumentException) {
            // Refused before any signed request was sent
        }

        $this->assertSame([], $client->calls);
    }

    public function testDomainPurgeInvalidatesTheDomainsTagOnItsTenant(): void
    {
        $client = new TestClient([$this->tenant('dt_example'), $this->created()]);

        $this->adapter($client)->purgeDomain('Example.com');

        $this->assertSame(self::API . '/distribution-tenant?domain=example.com', $client->calls[0]['url']);
        $this->assertSame(self::API . '/distribution-tenant/dt_example/invalidation', $client->calls[1]['url']);
        $this->assertSame(['#domain-example.com'], $this->paths($client, 1));
    }

    public function testDomainWithoutATenantHasNothingToPurge(): void
    {
        $client = new TestClient([new Response(404, body: new Stream($this->error('EntityNotFound', 'The entity was not found.')))]);

        // A domain still served only by another CDN has nothing cached on CloudFront.
        $this->adapter($client)->purgeDomain('example.com');

        $this->assertCount(1, $client->calls);
    }

    public function testFailedTenantLookupIsAnError(): void
    {
        $client = new TestClient([new Response(403, body: new Stream($this->error('AccessDenied', 'Access denied.')))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CloudFront tenant lookup failed with status 403: AccessDenied: Access denied.');

        $this->adapter($client)->purgeDomain('example.com');
    }

    public function testPurgesKeysAsTagsOnTheConfiguredTenant(): void
    {
        $client = new TestClient([$this->created()]);

        $this->adapter($client, 'dt_main')->purgeKeys(['host-deadbeef', 'deployment-12345']);

        $this->assertSame(self::API . '/distribution-tenant/dt_main/invalidation', $client->calls[0]['url']);
        $this->assertSame(['#host-deadbeef', '#deployment-12345'], $this->paths($client, 0));
    }

    public function testRefusesKeysCloudFrontCannotStoreBeforeSending(): void
    {
        $client = new TestClient([]);

        foreach (['summer sale', 'a,b', str_repeat('a', CloudFront::KEY_MAX_LENGTH + 1), ''] as $key) {
            try {
                $this->adapter($client, 'dt_main')->purgeKeys(['valid', $key]);
                $this->fail('Key "' . $key . '" must be refused');
            } catch (\InvalidArgumentException) {
                // Refused before the batch was sent
            }
        }

        $this->assertSame([], $client->calls);
    }

    public function testKeysAreInvalidatedInBatches(): void
    {
        $client = new TestClient(array_fill(0, 2, $this->created()));
        $keys = array_map(static fn (int $i): string => 'key-' . $i, range(1, CloudFront::KEYS_PER_PURGE + 1));

        $this->adapter($client, 'dt_main')->purgeKeys($keys);

        $this->assertCount(2, $client->calls);
        $this->assertCount(CloudFront::KEYS_PER_PURGE, $this->paths($client, 0));
        $this->assertSame(['#key-151'], $this->paths($client, 1));
        $this->assertStringContainsString('<Quantity>1</Quantity>', $this->body($client, 1));
    }

    public function testBatchesAreSpacedOutUnderTheQuota(): void
    {
        $client = new TestClient(array_fill(0, 2, $this->created()));
        $keys = array_fill(0, CloudFront::KEYS_PER_PURGE + 1, 'key');

        $started = hrtime(true);
        $this->adapter($client, 'dt_main', batchInterval: 50)->purgeKeys($keys);

        $this->assertGreaterThanOrEqual(50_000_000, hrtime(true) - $started);
    }

    public function testZonePurgeInvalidatesEverythingOnTheTenant(): void
    {
        $client = new TestClient([$this->created()]);

        $this->adapter($client, 'dt_main')->purgeZone();

        $this->assertSame(self::API . '/distribution-tenant/dt_main/invalidation', $client->calls[0]['url']);
        $this->assertSame(['/*'], $this->paths($client, 0));
    }

    public function testKeyPurgeRequiresTenantId(): void
    {
        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('tenant ID');

        $this->adapter(new TestClient([]))->purgeKeys(['key']);
    }

    public function testZonePurgeRequiresTenantId(): void
    {
        $this->expectException(UnsupportedOperation::class);

        $this->adapter(new TestClient([]))->purgeZone();
    }

    public function testThrottledInvalidationIsRetriedWithTheSameBatch(): void
    {
        $client = new TestClient([
            new Response(400, body: new Stream($this->error('TooManyInvalidationsInProgress', 'Too many in progress.'))),
            new Response(429, body: new Stream($this->error('Throttling', 'Rate exceeded.'))),
            $this->created(),
        ]);

        $this->adapter($client, 'dt_main')->purgeKeys(['key']);

        $this->assertCount(3, $client->calls);
        $this->assertSame($client->calls[0]['body'], $client->calls[2]['body']);
    }

    public function testThrottlingThatOutlastsTheRetriesIsReported(): void
    {
        $throttled = new Response(400, body: new Stream($this->error('TooManyInvalidationsInProgress', 'Too many in progress.')));
        $client = new TestClient(array_fill(0, CloudFront::THROTTLE_RETRIES + 1, $throttled));

        try {
            $this->adapter($client, 'dt_main')->purgeZone();
            $this->fail('A purge throttled on every attempt must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('TooManyInvalidationsInProgress', $error->getMessage());
        }

        $this->assertCount(CloudFront::THROTTLE_RETRIES + 1, $client->calls);
    }

    public function testRejectedInvalidationIsNotRetried(): void
    {
        $client = new TestClient([new Response(400, body: new Stream($this->error('InvalidArgument', 'Bad path.')))]);

        try {
            $this->adapter($client, 'dt_main')->purgeZone();
            $this->fail('An invalid batch must fail');
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertCount(1, $client->calls);
    }

    public function testFailedInvalidationReportsCloudFrontsError(): void
    {
        $client = new TestClient([new Response(400, body: new Stream($this->error('InvalidArgument', 'Bad path.')))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CloudFront invalidation failed with status 400: InvalidArgument: Bad path.');

        $this->adapter($client, 'dt_main')->purgeZone();
    }

    public function testRequestsAreSignedForCloudFront(): void
    {
        $client = new TestClient([$this->created()]);

        new CloudFront('AKIDEXAMPLE', 'secret', 'domain-', 'dt_main', 'session-token', $client, batchInterval: 0)->purgeZone();

        $authorization = $client->headers['authorization'] ?? '';

        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', $authorization);
        $this->assertStringContainsString('/us-east-1/cloudfront/aws4_request, SignedHeaders=content-type;host;x-amz-date;x-amz-security-token, Signature=', $authorization);
        $this->assertSame('session-token', $client->headers['x-amz-security-token'] ?? null);
    }

    private function adapter(TestClient $client, ?string $tenantId = null, int $batchInterval = 0): CloudFront
    {
        return new CloudFront('key', 'secret', 'domain-', $tenantId, client: $client, batchInterval: $batchInterval);
    }

    private function tenant(string $id): Response
    {
        return new Response(200, body: new Stream(
            '<?xml version="1.0" encoding="UTF-8"?><DistributionTenant xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
            . '<Customizations><Certificate><Arn>arn:aws:acm:us-east-1:1:certificate/x</Arn></Certificate></Customizations>'
            . '<DistributionId>EDFDVBD6EXAMPLE</DistributionId><Id>' . $id . '</Id></DistributionTenant>',
        ));
    }

    private function created(): Response
    {
        return new Response(201, body: new Stream(
            '<?xml version="1.0" encoding="UTF-8"?><Invalidation xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/"><Id>I1</Id><Status>InProgress</Status></Invalidation>',
        ));
    }

    private function error(string $code, string $message): string
    {
        return '<?xml version="1.0"?><ErrorResponse xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
            . '<Error><Type>Sender</Type><Code>' . $code . '</Code><Message>' . $message . '</Message></Error>'
            . '<RequestId>r</RequestId></ErrorResponse>';
    }

    private function body(TestClient $client, int $call): string
    {
        $body = $client->calls[$call]['body'];
        $this->assertIsString($body);

        return $body;
    }

    /**
     * @return array<int, string>
     */
    private function paths(TestClient $client, int $call): array
    {
        $batch = simplexml_load_string($this->body($client, $call));
        $this->assertNotFalse($batch);
        $this->assertSame((string) \count($batch->Paths->Items->Path), (string) $batch->Paths->Quantity);

        $paths = [];
        foreach ($batch->Paths->Items->Path as $path) {
            $paths[] = (string) $path;
        }

        return $paths;
    }
}
