<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests\Certificates\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\CloudFront;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Certificates\ValidationToken;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Cdn\Tests\TestClient;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class CloudFrontTest extends TestCase
{
    private const string API = 'https://cloudfront.amazonaws.com/2020-05-31';

    public function testIssueCertificateCreatesTenantWithManagedCertificate(): void
    {
        $client = new TestClient([
            $this->notFound(),
            $this->tenant(status: 201),
        ]);

        $provider = new CloudFront('AKID', 'secret', 'EDFDVBD6EXAMPLE', connectionGroupId: 'cg_1', parameters: ['origin' => 'fra&syd'], client: $client);

        $this->assertNull($provider->issueCertificate('ignored', 'Example.com', null));
        $this->assertCount(2, $client->calls);
        $this->assertSame('GET', $client->calls[0]['method']);
        $this->assertSame(self::API . '/distribution-tenant?domain=example.com', $client->calls[0]['url']);
        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame(self::API . '/distribution-tenant', $client->calls[1]['url']);

        $body = $this->xml($client->calls[1]['body']);
        $this->assertSame('CreateDistributionTenantRequest', $body->getName());
        $this->assertSame('EDFDVBD6EXAMPLE', (string) $body->DistributionId);
        $this->assertSame('example.com', (string) $body->Name);
        $this->assertSame('example.com', (string) $body->Domains->DomainItem->Domain);
        $this->assertSame('cg_1', (string) $body->ConnectionGroupId);
        $this->assertSame('origin', (string) $body->Parameters->Parameter->Name);
        $this->assertSame('fra&syd', (string) $body->Parameters->Parameter->Value);
        $this->assertSame('self-hosted', (string) $body->ManagedCertificateRequest->ValidationTokenHost);
        $this->assertSame('example.com', (string) $body->ManagedCertificateRequest->PrimaryDomainName);
        $this->assertSame('true', (string) $body->Enabled);

        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', $client->headers['authorization']);
        $this->assertStringContainsString('/us-east-1/cloudfront/aws4_request', $client->headers['authorization']);
    }

    public function testIssueCertificateLetsCloudFrontServeTheTokenWhenConfigured(): void
    {
        $client = new TestClient([$this->notFound(), $this->tenant(status: 201)]);

        new CloudFront('AKID', 'secret', 'dist', validationTokenHost: CloudFront::VALIDATION_TOKEN_HOST_CLOUDFRONT, client: $client)
            ->issueCertificate('ignored', 'example.com', null);

        $body = $this->xml($client->calls[1]['body']);
        $this->assertSame('cloudfront', (string) $body->ManagedCertificateRequest->ValidationTokenHost);
        $this->assertSame(0, $body->ConnectionGroupId->count());
        $this->assertSame(0, $body->Parameters->count());
    }

    public function testIssueCertificateFindsTenantCreatedConcurrently(): void
    {
        $client = new TestClient([
            $this->notFound(),
            $this->error(409, 'EntityAlreadyExists'),
            $this->tenant(),
        ]);

        $this->assertNull($this->provider($client)->issueCertificate('ignored', 'example.com', null));
        $this->assertCount(3, $client->calls);
    }

    public function testIssueCertificateRejectsDomainHeldElsewhere(): void
    {
        $client = new TestClient([
            $this->notFound(),
            $this->error(409, 'CNAMEAlreadyExists', 'One or more of the CNAMEs you provided are already associated with a different resource.'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CNAMEAlreadyExists');
        $this->provider($client)->issueCertificate('ignored', 'example.com', null);
    }

    public function testIssueCertificateReturnsRecheckDateOnceIssued(): void
    {
        $client = new TestClient([$this->tenant(), $this->certificate('issued')]);

        $renewDate = $this->provider($client)->issueCertificate('ignored', 'example.com', null);

        $this->assertNotNull($renewDate);
        $expected = new \DateTimeImmutable()->modify(CloudFront::RECHECK_INTERVAL);
        $this->assertEqualsWithDelta($expected->getTimestamp(), new \DateTimeImmutable($renewDate)->getTimestamp(), 60);
        $this->assertCount(2, $client->calls);
        $this->assertSame(self::API . '/managed-certificate/tenant_1', $client->calls[1]['url']);
    }

    public function testIssueCertificateWaitsOnPendingValidation(): void
    {
        $client = new TestClient([$this->tenant(), $this->certificate('pending-validation')]);

        $this->assertNull($this->provider($client)->issueCertificate('ignored', 'example.com', null));
        $this->assertCount(2, $client->calls);
    }

    public function testIssueCertificateRequestsAgainAfterFailure(): void
    {
        $customizations = '<Customizations><Certificate><Arn>arn:aws:acm:us-east-1:1:certificate/old</Arn></Certificate><WebAcl><Action>override</Action><Arn>arn:aws:wafv2:acl</Arn></WebAcl></Customizations>';
        $client = new TestClient([
            $this->tenant(customizations: $customizations),
            $this->certificate('validation-timed-out'),
            $this->tenant(etag: 'E2'),
        ]);

        $this->assertNull($this->provider($client)->issueCertificate('ignored', 'example.com', null));
        $this->assertCount(3, $client->calls);
        $this->assertSame('PUT', $client->calls[2]['method']);
        $this->assertSame(self::API . '/distribution-tenant/tenant_1', $client->calls[2]['url']);
        $this->assertSame('E1', $client->headers['if-match']);

        $body = $this->xml($client->calls[2]['body']);
        $this->assertSame('UpdateDistributionTenantRequest', $body->getName());
        $this->assertSame('dist', (string) $body->DistributionId);
        $this->assertSame('example.com', (string) $body->Domains->DomainItem->Domain);
        $this->assertSame('cg_1', (string) $body->ConnectionGroupId);
        $this->assertSame('origin', (string) $body->Parameters->Parameter->Name);
        $this->assertSame('self-hosted', (string) $body->ManagedCertificateRequest->ValidationTokenHost);
        $this->assertSame(0, $body->Customizations->Certificate->count());
        $this->assertSame('arn:aws:wafv2:acl', (string) $body->Customizations->WebAcl->Arn);
        $this->assertSame('true', (string) $body->Enabled);
    }

    public function testIssueCertificateRequestsOneForTenantWithout(): void
    {
        $client = new TestClient([$this->tenant(), $this->notFound(), $this->tenant(etag: 'E2')]);

        $this->assertNull($this->provider($client)->issueCertificate('ignored', 'example.com', null));
        $this->assertSame('PUT', $client->calls[2]['method']);
        $this->assertSame(1, $this->xml($client->calls[2]['body'])->ManagedCertificateRequest->count());
    }

    public function testIssueCertificateEnablesDisabledTenant(): void
    {
        $client = new TestClient([
            $this->tenant(enabled: false),
            $this->certificate('issued'),
            $this->tenant(etag: 'E2'),
        ]);

        $this->assertNotNull($this->provider($client)->issueCertificate('ignored', 'example.com', null));

        $body = $this->xml($client->calls[2]['body']);
        $this->assertSame('true', (string) $body->Enabled);
        $this->assertSame(0, $body->ManagedCertificateRequest->count());
    }

    public function testLongDomainsGetHashedTenantNames(): void
    {
        $domain = str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.' . str_repeat('c', 20) . '.example.com';
        $client = new TestClient([$this->notFound(), $this->tenant(status: 201)]);

        $this->provider($client)->issueCertificate('ignored', $domain, null);

        $name = (string) $this->xml($client->calls[1]['body'])->Name;
        $this->assertSame(128, \strlen($name));
        $this->assertStringEndsWith('-' . hash('sha256', $domain), $name);
        $this->assertTrue(ctype_alnum($name[0]) && ctype_alnum($name[-1]));
        $this->assertSame('', trim($name, 'abcdefghijklmnopqrstuvwxyz0123456789.-'));
    }

    /** @return array<string, array{string, string}> */
    public static function statuses(): array
    {
        return [
            'pending validation' => ['pending-validation', Status::PENDING],
            'issued' => ['issued', Status::ISSUED],
            'inactive' => ['inactive', Status::UNKNOWN],
        ];
    }

    #[DataProvider('statuses')]
    public function testGetCertificateStatusMapsCertificateStatus(string $certificateStatus, string $expected): void
    {
        $client = new TestClient([$this->tenant(), $this->certificate($certificateStatus)]);

        $this->assertSame($expected, $this->provider($client)->getCertificateStatus('example.com', null));
    }

    /** @return array<string, array{string}> */
    public static function failures(): array
    {
        return [
            'validation timed out' => ['validation-timed-out'],
            'failed' => ['failed'],
            'expired' => ['expired'],
            'revoked' => ['revoked'],
        ];
    }

    #[DataProvider('failures')]
    public function testGetCertificateStatusThrowsWhenAwsStoppedTrying(string $certificateStatus): void
    {
        $client = new TestClient([$this->tenant(), $this->certificate($certificateStatus)]);

        try {
            $this->provider($client)->getCertificateStatus('example.com', null);
            $this->fail('Expected a certificate exception.');
        } catch (Certificate $exception) {
            $this->assertSame(Status::FAILED, $exception->getStatus());
            $this->assertStringContainsString($certificateStatus, $exception->getMessage());
        }
    }

    public function testGetCertificateStatusIsUnknownWithoutTenant(): void
    {
        $this->assertSame(Status::UNKNOWN, $this->provider(new TestClient([$this->notFound()]))->getCertificateStatus('example.com', null));
    }

    public function testIsRenewRequired(): void
    {
        $this->assertTrue($this->provider(new TestClient([$this->notFound()]))->isRenewRequired('example.com', null));
        $this->assertTrue($this->provider(new TestClient([$this->tenant(), $this->notFound()]))->isRenewRequired('example.com', null));
        $this->assertTrue($this->provider(new TestClient([$this->tenant(), $this->certificate('failed')]))->isRenewRequired('example.com', null));
        $this->assertFalse($this->provider(new TestClient([$this->tenant(), $this->certificate('pending-validation')]))->isRenewRequired('example.com', null));
        $this->assertFalse($this->provider(new TestClient([$this->tenant(), $this->certificate('issued')]))->isRenewRequired('example.com', null));
    }

    public function testGetValidationTokensWhilePendingValidation(): void
    {
        $client = new TestClient([$this->tenant(), $this->certificate('pending-validation')]);

        $tokens = $this->provider($client)->getValidationTokens('example.com');

        $this->assertEquals([new ValidationToken(
            'example.com',
            'http://example.com/.well-known/pki-validation/abc.txt',
            'https://validation.us-east-1.acm-validations.aws/123/.well-known/pki-validation/abc.txt',
        )], $tokens);
    }

    public function testGetValidationTokensIsEmptyOnceIssued(): void
    {
        $this->assertSame([], $this->provider(new TestClient([$this->tenant(), $this->certificate('issued')]))->getValidationTokens('example.com'));
        $this->assertSame([], $this->provider(new TestClient([$this->notFound()]))->getValidationTokens('example.com'));
    }

    public function testDeleteCertificateDisablesThenDeletesTenant(): void
    {
        $client = new TestClient([
            $this->tenant(),
            $this->tenant(etag: 'E2', enabled: false),
            new Response(204),
        ]);

        $this->provider($client)->deleteCertificate('example.com');

        $this->assertCount(3, $client->calls);
        $this->assertSame('PUT', $client->calls[1]['method']);
        $this->assertSame('false', (string) $this->xml($client->calls[1]['body'])->Enabled);
        $this->assertSame(0, $this->xml($client->calls[1]['body'])->ManagedCertificateRequest->count());
        $this->assertSame('DELETE', $client->calls[2]['method']);
        $this->assertSame(self::API . '/distribution-tenant/tenant_1', $client->calls[2]['url']);
        $this->assertSame('E2', $client->headers['if-match']);
    }

    public function testDeleteCertificateSkipsDisableForDisabledTenant(): void
    {
        $client = new TestClient([$this->tenant(enabled: false), new Response(204)]);

        $this->provider($client)->deleteCertificate('example.com');

        $this->assertSame('DELETE', $client->calls[1]['method']);
        $this->assertSame('E1', $client->headers['if-match']);
    }

    public function testDeleteCertificateIgnoresMissingTenant(): void
    {
        $client = new TestClient([$this->notFound()]);

        $this->provider($client)->deleteCertificate('example.com');

        $this->assertCount(1, $client->calls);
    }

    public function testLeavesTenantsItDoesNotOwnAlone(): void
    {
        $wildcard = new TestClient([$this->tenant(domains: ['*.example.com'])]);
        $this->provider($wildcard)->deleteCertificate('app.example.com');
        $this->assertCount(1, $wildcard->calls);

        $shared = new TestClient([$this->tenant(domains: ['example.com', 'www.example.com'])]);
        $this->provider($shared)->deleteCertificate('example.com');
        $this->assertCount(1, $shared->calls);

        $foreign = new TestClient([$this->tenant(distributionId: 'other')]);
        $this->provider($foreign)->deleteCertificate('example.com');
        $this->assertCount(1, $foreign->calls);
    }

    public function testDeleteCertificateReportsTenantStillDeploying(): void
    {
        $client = new TestClient([$this->tenant(enabled: false), $this->error(409, 'ResourceNotDisabled')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disabled but not yet deployed');
        $this->provider($client)->deleteCertificate('example.com');
    }

    public function testRejectsUnknownValidationTokenHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CloudFront('AKID', 'secret', 'dist', validationTokenHost: 'dns');
    }

    private function provider(TestClient $client): CloudFront
    {
        return new CloudFront('AKID', 'secret', 'dist', connectionGroupId: 'cg_1', client: $client);
    }

    /** @param list<string> $domains */
    private function tenant(int $status = 200, string $etag = 'E1', bool $enabled = true, string $customizations = '', array $domains = ['example.com'], string $distributionId = 'dist'): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<DistributionTenant xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
            . '<Id>tenant_1</Id><Arn>arn:aws:cloudfront::1:distribution-tenant/tenant_1</Arn><Name>example.com</Name>'
            . '<DistributionId>' . $distributionId . '</DistributionId><ConnectionGroupId>cg_1</ConnectionGroupId>'
            . $customizations
            . '<Domains>' . implode('', array_map(static fn (string $domain): string => '<DomainResult><Domain>' . $domain . '</Domain><Status>inactive</Status></DomainResult>', $domains)) . '</Domains>'
            . '<Parameters><Parameter><Name>origin</Name><Value>fra</Value></Parameter></Parameters>'
            . '<Enabled>' . ($enabled ? 'true' : 'false') . '</Enabled><Status>InProgress</Status>'
            . '</DistributionTenant>';

        return new Response($status, body: new Stream($xml))->withHeader('ETag', $etag);
    }

    private function certificate(string $status): Response
    {
        return new Response(200, body: new Stream(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ManagedCertificateDetails xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
            . '<CertificateArn>arn:aws:acm:us-east-1:1:certificate/new</CertificateArn>'
            . '<CertificateStatus>' . $status . '</CertificateStatus>'
            . '<ValidationTokenHost>self-hosted</ValidationTokenHost>'
            . '<ValidationTokenDetails><ValidationTokenDetail>'
            . '<Domain>example.com</Domain>'
            . '<RedirectFrom>http://example.com/.well-known/pki-validation/abc.txt</RedirectFrom>'
            . '<RedirectTo>https://validation.us-east-1.acm-validations.aws/123/.well-known/pki-validation/abc.txt</RedirectTo>'
            . '</ValidationTokenDetail></ValidationTokenDetails>'
            . '</ManagedCertificateDetails>'
        ));
    }

    private function notFound(): Response
    {
        return $this->error(404, 'EntityNotFound');
    }

    private function error(int $status, string $code, string $message = ''): Response
    {
        return new Response($status, body: new Stream(
            '<?xml version="1.0"?><ErrorResponse xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
            . '<Error><Type>Sender</Type><Code>' . $code . '</Code><Message>' . $message . '</Message></Error>'
            . '<RequestId>req</RequestId></ErrorResponse>'
        ));
    }

    private function xml(mixed $body): \SimpleXMLElement
    {
        $this->assertIsString($body);
        $xml = simplexml_load_string($body);
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);

        return $xml;
    }
}
