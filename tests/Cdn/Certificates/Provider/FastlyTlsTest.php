<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Challenge;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Certificate;
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

    #[DataProvider('waitingStates')]
    public function testBlockedAuthorizationReportsTheDnsChallenge(string $state): void
    {
        $client = new TestClient([$this->json($this->subscription(state: $state))]);
        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);

        try {
            $provider->getCertificateStatus('example.com', null);
            $this->fail('A blocked authorization has to be reported, not waited on.');
        } catch (Certificate $exception) {
            $this->assertSame(Status::BLOCKED, $exception->getStatus());
            $this->assertTrue($exception->isBlocked());
            $this->assertEquals(
                [new Challenge(FastlyTls::CHALLENGE_MANAGED_DNS, 'CNAME', '_acme-challenge.example.com', ['token.fastly-validations.com'])],
                $exception->getChallenges(),
            );
            $this->assertSame([], $exception->getWarnings());
            $this->assertStringContainsString(
                'Create a CNAME record for _acme-challenge.example.com pointing to token.fastly-validations.com.',
                $exception->getMessage(),
            );
        }

        $this->assertStringContainsString('include=tls_certificates%2Ctls_authorizations', $client->calls[0]['url']);
    }

    /** @return iterable<string, array{string}> */
    public static function waitingStates(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'processing' => ['processing'];
        yield 'renewing' => ['renewing'];
    }

    public function testBlockedAuthorizationReportsFastlyWarnings(): void
    {
        $warning = 'Conflicting record(s) found at _acme-challenge.example.com. Please remove the record(s) and add the following CNAME record: token.fastly-validations.com';
        $client = new TestClient([$this->json($this->subscription(warnings: [['type' => 'dns', 'instructions' => $warning]]))]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A blocked authorization has to be reported, not waited on.');
        } catch (Certificate $exception) {
            $this->assertSame(Status::BLOCKED, $exception->getStatus());
            $this->assertSame([$warning], $exception->getWarnings());
            $this->assertCount(1, $exception->getChallenges());
            $this->assertStringContainsString($warning, $exception->getMessage());
        }
    }

    public function testPendingAuthorizationIsStillWaitedOn(): void
    {
        $client = new TestClient([$this->json($this->subscription(authorizationState: 'pending'))]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
    }

    public function testFailedSubscriptionReportsTheDnsChallenge(): void
    {
        $client = new TestClient([$this->json($this->subscription(state: 'failed'))]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A failed subscription has to be reported.');
        } catch (Certificate $exception) {
            $this->assertSame(Status::FAILED, $exception->getStatus());
            $this->assertFalse($exception->isBlocked());
            $this->assertCount(1, $exception->getChallenges());
            $this->assertStringContainsString('stopped trying', $exception->getMessage());
            $this->assertStringContainsString('_acme-challenge.example.com', $exception->getMessage());
        }
    }

    public function testFailedSubscriptionWithoutAuthorizationsIsStillReported(): void
    {
        $client = new TestClient([$this->json('{"data":[{"id":"sub_123","attributes":{"state":"failed"}}]}')]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A failed subscription has to be reported.');
        } catch (Certificate $exception) {
            $this->assertSame(Status::FAILED, $exception->getStatus());
            $this->assertSame([], $exception->getChallenges());
            $this->assertSame([], $exception->getWarnings());
            $this->assertSame('Fastly stopped trying to issue a certificate for example.com.', $exception->getMessage());
        }
    }

    public function testIssuedSubscriptionIgnoresStaleAuthorization(): void
    {
        $client = new TestClient([$this->json($this->subscription(state: 'issued'))]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
    }

    public function testAuthorizationOfAnotherDomainIsIgnored(): void
    {
        $body = $this->subscription();
        $body['included'][0]['relationships']['tls_domain']['data']['id'] = 'other.example.com';
        $client = new TestClient([$this->json($body)]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
    }

    public function testUnreferencedAuthorizationIsIgnored(): void
    {
        $body = $this->subscription();
        $body['data'][0]['relationships']['tls_authorizations']['data'] = [['id' => 'auth_2', 'type' => 'tls_authorization']];
        $client = new TestClient([$this->json($body)]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
    }

    public function testMalformedChallengesAreSkipped(): void
    {
        $client = new TestClient([$this->json($this->subscription(challenges: [
            ['type' => 'managed-dns', 'record_type' => 'CNAME', 'record_name' => '_acme-challenge.example.com'],
            'not a challenge',
            ['type' => 'managed-http-a', 'record_type' => 'a', 'record_name' => 'example.com', 'values' => ['151.101.0.0', 42, '']],
        ]))]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A blocked authorization has to be reported, not waited on.');
        } catch (Certificate $exception) {
            $this->assertSame(Status::BLOCKED, $exception->getStatus());
            $this->assertEquals([new Challenge('managed-http-a', 'A', 'example.com', ['151.101.0.0'])], $exception->getChallenges());
        }
    }

    public function testSeveralChallengesAreOfferedAsAlternatives(): void
    {
        $client = new TestClient([$this->json($this->subscription(challenges: [
            ['type' => 'managed-dns', 'record_type' => 'CNAME', 'record_name' => '_acme-challenge.example.com', 'values' => ['token.fastly-validations.com']],
            ['type' => 'managed-http-a', 'record_type' => 'A', 'record_name' => 'example.com', 'values' => ['151.101.0.0', '151.101.64.0']],
        ]))]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A blocked authorization has to be reported, not waited on.');
        } catch (Certificate $exception) {
            $this->assertCount(2, $exception->getChallenges());
            $this->assertStringContainsString(
                'Create one of these DNS records: CNAME _acme-challenge.example.com -> token.fastly-validations.com; A example.com -> 151.101.0.0 or 151.101.64.0.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A Fastly TLS subscription as `GET /tls/subscriptions?include=tls_certificates,tls_authorizations`
     * returns it while the domain owner still has to add the `_acme-challenge` CNAME.
     *
     * @param list<array{type:string,instructions:string}> $warnings
     * @param list<mixed>|null $challenges
     * @return array<string, mixed>
     */
    private function subscription(string $state = 'pending', string $authorizationState = 'blocked', array $warnings = [], ?array $challenges = null): array
    {
        return [
            'data' => [[
                'id' => 'sub_123',
                'type' => 'tls_subscription',
                'attributes' => ['certificate_authority' => 'certainly', 'state' => $state, 'has_active_order' => true],
                'relationships' => [
                    'tls_authorizations' => ['data' => [['id' => 'auth_1', 'type' => 'tls_authorization']]],
                    'tls_certificates' => ['data' => []],
                    'tls_domains' => ['data' => [['id' => 'example.com', 'type' => 'tls_domain']]],
                ],
            ]],
            'included' => [[
                'id' => 'auth_1',
                'type' => 'tls_authorization',
                'attributes' => [
                    'challenges' => $challenges ?? [[
                        'type' => 'managed-dns',
                        'record_type' => 'CNAME',
                        'record_name' => '_acme-challenge.example.com',
                        'values' => ['token.fastly-validations.com'],
                    ]],
                    'state' => $authorizationState,
                    'warnings' => $warnings,
                ],
                'relationships' => ['tls_domain' => ['data' => ['id' => 'example.com', 'type' => 'tls_domain']]],
            ]],
        ];
    }

    /** @param array<string, mixed>|string $body */
    private function json(array|string $body): Response
    {
        return new Response(200, body: new Stream(\is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
