<?php

declare(strict_types=1);

namespace Utopia\Cdn\Tests\Certificates\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Challenge;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Cdn\Tests\TestClient;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

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
        $issued = '{"data":[{"id":"sub_123","attributes":{"state":"issued"},"relationships":{"tls_certificates":{"data":[{"type":"tls_certificate","id":"cert_1"}]}}}]}';
        $client = new TestClient([
            new Response(200, body: new Stream($issued)),
            new Response(200, body: new Stream('{"data":[{"id":"act_1","type":"tls_activation"}]}')),
            new Response(200, body: new Stream($issued)),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);

        $this->assertSame(Status::ISSUED, $provider->getCertificateStatus('example.com', null));
        $this->assertFalse($provider->isRenewRequired('example.com', null));
        $this->assertSame([], \array_column($this->activations($client), 'domain'), 'An activation that already exists is left alone.');
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
        $client = new TestClient([
            new Response(200, body: new Stream(json_encode([
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
            ]))),
            new Response(200, body: new Stream('{"data":[]}')),
            new Response(201, body: new Stream('{"data":{"id":"act_1","type":"tls_activation"}}')),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $this->assertSame('2027-01-02 00:00:00.000', $provider->issueCertificate('cert', 'example.com', null));
        $this->assertSame(['example.com'], \array_column($this->activations($client), 'domain'));
        $this->assertSame(['cert_1'], \array_column($this->activations($client), 'certificate'));
    }

    public function testRetriesFailedSubscriptionWithForce(): void
    {
        // A subscription fails on a renewal while the certificate it issued
        // earlier still serves, and Fastly refuses to edit a subscription with
        // such an active domain unless the request carries force.
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"failed"}}]}')),
            new Response(200, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"processing"}}}')),
        ]);
        $provider = new FastlyTls('token', 'config', 'certainly', $client);
        $this->assertNull($provider->issueCertificate('cert', 'example.com', null));
        $this->assertSame('PATCH', $client->calls[1]['method']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_123?force=true', $client->calls[1]['url']);
        $this->assertSame('retry', $client->calls[1]['body']['data']['attributes']['state']);
    }

    public function testRefusedRetrySurfacesFastlysReason(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"failed"}}]}')),
            new Response(400, body: new Stream('{"errors":[{"title":"Bad Request","detail":"Subscription has active domains"}]}')),
        ]);
        $provider = new FastlyTls('token', 'config', 'certainly', $client);

        try {
            $provider->issueCertificate('cert', 'example.com', null);
            $this->fail('Expected the refused retry to surface.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Failed to retry Fastly TLS subscription with status 400', $error->getMessage());
            $this->assertStringContainsString('Subscription has active domains', $error->getMessage());
        }

        // Nothing else is attempted: the deployed certificate is left serving.
        $this->assertCount(2, $client->calls);
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
        $body = $this->subscription(state: 'issued', certificates: [['type' => 'tls_certificate', 'id' => 'cert_1']]);
        $client = new TestClient([
            $this->json($body),
            $this->json(['data' => [['id' => 'act_1', 'type' => 'tls_activation']]]),
        ]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
        $this->assertCount(2, $client->calls);
    }

    public function testIssuedSubscriptionWithoutACertificateIsStillProcessing(): void
    {
        // Fastly reports a subscription issued slightly before the certificate
        // appears on it. There is nothing to attach yet, so the hostname is not
        // ready -- and reporting it issued would mark the domain verified while
        // the edge still serves the default certificate.
        $client = new TestClient([$this->json($this->subscription(state: 'issued'))]);

        $this->assertSame(
            Status::PROCESSING,
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null),
        );
        $this->assertSame([], \array_column($this->activations($client), 'domain'));
    }

    public function testRenewingSubscriptionWithoutACertificateIsStillProcessing(): void
    {
        // Renewing is reported ready, so it needs the same guard as issued. No
        // certificate reference at all means no old certificate is serving either,
        // so nothing is attached and the hostname is not ready.
        $client = new TestClient([$this->json($this->subscription(state: 'renewing', authorizationState: 'passing'))]);

        $this->assertSame(
            Status::PROCESSING,
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null),
        );
        $this->assertSame([], \array_column($this->activations($client), 'domain'));
    }

    public function testRenewingSubscriptionActivatesItsCertificate(): void
    {
        // The guard must not swallow the normal case: a renewing subscription that
        // does carry a certificate is still reported renewing, and gets attached.
        $client = new TestClient([
            $this->json($this->subscription(
                state: 'renewing',
                authorizationState: 'passing',
                certificates: [['type' => 'tls_certificate', 'id' => 'cert_1']],
            )),
            $this->json(['data' => []]),
            $this->json(['data' => ['id' => 'act_1', 'type' => 'tls_activation']], 201),
        ]);

        $this->assertSame(
            Status::RENEWING,
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null),
        );
        $this->assertSame(['example.com'], \array_column($this->activations($client), 'domain'));
    }

    public function testIssuedCertificateWithoutATlsConfigurationIsNotActivated(): void
    {
        $client = new TestClient([$this->json($this->subscription(state: 'issued'))]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', '', 'certainly', $client)->getCertificateStatus('example.com', null));
        $this->assertCount(1, $client->calls);
    }

    public function testIssuedApexWithoutAnActivationIsActivated(): void
    {
        $client = new TestClient([
            $this->json($this->issuedSubscription('example.com')),
            $this->json(['data' => []]),
            $this->json(['data' => ['id' => 'act_apex', 'type' => 'tls_activation']], 201),
        ]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
        $this->assertSame(['example.com'], \array_column($this->activations($client), 'domain'));
    }

    public function testIssuedWwwWithoutAnActivationIsActivated(): void
    {
        $client = new TestClient([
            $this->json($this->issuedSubscription('www.example.com')),
            $this->json(['data' => []]),
            $this->json(['data' => ['id' => 'act_www', 'type' => 'tls_activation']], 201),
        ]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('www.example.com', null));
        $this->assertSame(['www.example.com'], \array_column($this->activations($client), 'domain'));
    }

    public function testMultiSanCertificateActivatesApexAndWww(): void
    {
        $body = $this->issuedSubscription(
            'example.com',
            ['example.com', 'www.example.com'],
            'cert_new',
            '2027-06-01T00:00:00Z',
            [['id' => 'cert_old', 'notAfter' => '2026-01-01T00:00:00Z']],
        );
        $client = new TestClient([
            $this->json($body),
            $this->json(['data' => []]),
            $this->json(['data' => ['id' => 'act_apex', 'type' => 'tls_activation']], 201),
            $this->json(['data' => []]),
            $this->json(['data' => ['id' => 'act_www', 'type' => 'tls_activation']], 201),
        ]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));

        // Both names on the certificate are attached, and to the newest of the
        // two certificates the subscription carries.
        $this->assertSame(['example.com', 'www.example.com'], \array_column($this->activations($client), 'domain'));
        $this->assertSame(['cert_new', 'cert_new'], \array_column($this->activations($client), 'certificate'));
    }

    public function testExistingActivationIsNotCreatedAgain(): void
    {
        $client = new TestClient([
            $this->json($this->issuedSubscription('example.com')),
            $this->json(['data' => [['id' => 'act_1', 'type' => 'tls_activation']]]),
        ]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
        $this->assertCount(2, $client->calls);
    }

    public function testActivationConflictIsAcceptedOnceOurCertificateIsConfirmed(): void
    {
        $client = new TestClient([
            $this->json($this->issuedSubscription('example.com')),
            $this->json(['data' => []]),
            new Response(409, body: new Stream('{"errors":[{"title":"Conflict","detail":"Activation already exists"}]}')),
            $this->json(['data' => [['id' => 'act_1', 'type' => 'tls_activation']]]),
        ]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null));
    }

    public function testActivationConflictWithAnotherCertificateFails(): void
    {
        // A conflict can mean another certificate already terminates TLS for the
        // hostname. Accepting it would report the domain ready while the edge
        // serves someone else's certificate, so the re-check has to decide.
        $client = new TestClient([
            $this->json($this->issuedSubscription('example.com')),
            $this->json(['data' => []]),
            new Response(409, body: new Stream('{"errors":[{"title":"Conflict","detail":"Domain already has an activation"}]}')),
            $this->json(['data' => []]),
        ]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A conflict with another certificate is not an activation.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Another certificate already terminates TLS for example.com', $error->getMessage());
        }
    }

    public function testActivationFailureIsNotReportedAsIssued(): void
    {
        $client = new TestClient([
            $this->json($this->issuedSubscription('example.com')),
            $this->json(['data' => []]),
            new Response(400, body: new Stream('{"errors":[{"title":"Bad Request","detail":"TLS configuration not found"}]}')),
        ]);

        try {
            new FastlyTls('token', 'tls-config-id', 'certainly', $client)->getCertificateStatus('example.com', null);
            $this->fail('A missing activation has to fail the status check.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Failed to activate Fastly TLS certificate for example.com with status 400', $error->getMessage());
            $this->assertStringContainsString('TLS configuration not found', $error->getMessage());
        }
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
     * @param list<array{type: string, id: string}> $certificates
     * @return array<string, mixed>
     */
    private function subscription(string $state = 'pending', string $authorizationState = 'blocked', array $warnings = [], ?array $challenges = null, array $certificates = []): array
    {
        return [
            'data' => [[
                'id' => 'sub_123',
                'type' => 'tls_subscription',
                'attributes' => ['certificate_authority' => 'certainly', 'state' => $state, 'has_active_order' => true],
                'relationships' => [
                    'tls_authorizations' => ['data' => [['id' => 'auth_1', 'type' => 'tls_authorization']]],
                    'tls_certificates' => ['data' => $certificates],
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

    /**
     * An issued subscription whose certificate covers $domains. $certificateId is
     * the newest certificate; $alsoCertificates are older ones still referenced,
     * which is what a renewal looks like.
     *
     * @param list<string> $domains
     * @param list<array{id: string, notAfter: string}> $alsoCertificates
     * @return array<string, mixed>
     */
    private function issuedSubscription(
        string $domain,
        array $domains = [],
        string $certificateId = 'cert_1',
        string $notAfter = '2027-02-01T00:00:00Z',
        array $alsoCertificates = [],
    ): array {
        if ($domains === []) {
            $domains = [$domain];
        }

        $references = [['type' => 'tls_certificate', 'id' => $certificateId]];
        $included = [['type' => 'tls_certificate', 'id' => $certificateId, 'attributes' => ['not_after' => $notAfter]]];

        foreach ($alsoCertificates as $certificate) {
            $references[] = ['type' => 'tls_certificate', 'id' => $certificate['id']];
            $included[] = [
                'type' => 'tls_certificate',
                'id' => $certificate['id'],
                'attributes' => ['not_after' => $certificate['notAfter']],
            ];
        }

        return [
            'data' => [[
                'id' => 'sub_123',
                'type' => 'tls_subscription',
                'attributes' => ['certificate_authority' => 'certainly', 'state' => 'issued'],
                'relationships' => [
                    'tls_certificates' => ['data' => $references],
                    'tls_domains' => ['data' => array_map(
                        static fn (string $name): array => ['id' => $name, 'type' => 'tls_domain'],
                        $domains,
                    )],
                ],
            ]],
            'included' => $included,
        ];
    }

    /**
     * Each activation the provider created, in order: which hostname it attached
     * and which certificate it attached there.
     *
     * @return list<array{domain: string, certificate: string}>
     */
    private function activations(TestClient $client): array
    {
        $activations = [];

        foreach ($client->calls as $call) {
            if ($call['method'] !== 'POST' || !\str_contains($call['url'], '/tls/activations')) {
                continue;
            }

            // Only `body` is mixed on a recorded call; url and method are typed.
            $data = \is_array($call['body']) ? ($call['body']['data'] ?? null) : null;
            $relationships = \is_array($data) ? ($data['relationships'] ?? null) : null;
            if (!\is_array($relationships)) {
                continue;
            }

            $activations[] = [
                'domain' => self::reference($relationships, 'tls_domain'),
                'certificate' => self::reference($relationships, 'tls_certificate'),
            ];
        }

        return $activations;
    }

    /**
     * @param array<array-key, mixed> $relationships
     */
    private static function reference(array $relationships, string $name): string
    {
        $entry = $relationships[$name] ?? null;
        $data = \is_array($entry) ? ($entry['data'] ?? null) : null;
        $id = \is_array($data) ? ($data['id'] ?? null) : null;

        return \is_string($id) ? $id : '';
    }

    /** @param array<string, mixed>|string $body */
    private function json(array|string $body, int $status = 200): Response
    {
        return new Response($status, body: new Stream(\is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
