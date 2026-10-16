<?php

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Entire;

/**
 * Entire whose JWKS is a local fixture, so receipt verification runs the
 * real code path - key lookup included - without touching the network.
 */
class FixtureKeysEntire extends Entire
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $fixtureKeys = [];

    #[\Override]
    protected function jwks(string $issuer, bool $refresh = false): array
    {
        return $this->fixtureKeys;
    }
}

/**
 * Entire whose check run write is handed back instead of sent, so a report's
 * mapping onto a suite and its run can be read.
 */
class CheckRunsEntire extends Entire
{
    /**
     * @return array{branch: string, check_suite: array<string, mixed>, check_runs: list<array<string, mixed>>}
     */
    public function write(string $state, string $name, string $title, string $summary = '', string $detailsUrl = ''): array
    {
        return $this->checkRuns('main', $state, $name, $title, $summary, $detailsUrl);
    }
}

/**
 * Exercises everything Entire can prove without credentials: delivery token
 * verification and delivery parsing, which never touch the network. The
 * live half of the shared adapter suite cannot run at all - see
 * testLiveAdapterSuite().
 */
final class EntireTest extends TestCase
{
    protected const string REPOSITORY_ID = 'repo_01ARZ3NDEKTSV4RRFFQ69G5FAV';
    protected const string REPOSITORY_NAME = 'test-repo';
    protected const string OWNER = 'test-project';
    protected const string COMMIT_HASH = 'def4567890def4567890def4567890def4567890';
    protected const string PREVIOUS_COMMIT_HASH = 'abc1234567abc1234567abc1234567abc1234567';
    protected const string HEAD_BRANCH = 'feature-branch';
    protected const string INSTALLATION_ID = 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV';
    protected const string PLUGIN = 'appwrite';
    protected const string ISSUER = 'https://appwrite.test';
    protected const string CORE = 'https://eu.auth.entire.io';
    protected const string CELL = 'https://aws-us-east-2.api.entire.io';

    protected Entire $adapter;

    /**
     * Verification and parsing need no installation, so the adapter stays
     * uninitialized - initializeVariables() would reach for the network.
     */
    protected function setUp(): void
    {
        $this->adapter = new Entire(new Cache(new None()));
        $this->adapter->setIssuer(self::ISSUER);
    }

    /**
     * Encode a JWT the way Entire does: EdDSA over
     * "<base64url header>.<base64url claims>". $secretKey is a libsodium
     * secret key.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     * @param non-empty-string $secretKey
     */
    protected function signJwt(array $header, array $claims, string $secretKey): string
    {
        $encode = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $signingInput = $encode(json_encode($header) ?: '') . '.' . $encode(json_encode($claims) ?: '');

        return $signingInput . '.' . $encode(sodium_crypto_sign_detached($signingInput, $secretKey));
    }

    /**
     * The claims a cell puts on a delivery token for $payload.
     *
     * @return array<string, mixed>
     */
    protected function deliveryClaims(string $payload): array
    {
        return [
            'iss' => self::CELL,
            'aud' => self::PLUGIN,
            'iat' => time(),
            'exp' => time() + 300,
            'jti' => 'jti_0123456789',
            'body_sha256' => rtrim(strtr(base64_encode(hash('sha256', $payload, true)), '+/', '-_'), '='),
        ];
    }

    public function testValidateWebhookEvent(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $payload = '{"delivery_id":"dlv_0123456789"}';
        $header = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'JWT'];
        $token = $this->signJwt($header, $this->deliveryClaims($payload), $secretKey);

        $this->assertTrue($this->adapter->validateWebhookEvent($payload, 'Bearer ' . $token, $publicKey));
        $this->assertTrue($this->adapter->validateWebhookEvent($payload, $token, $publicKey));
        $this->assertFalse($this->adapter->validateWebhookEvent($payload, 'not-a-token', $publicKey));

        // A token by a different key must not verify
        $otherSecretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->assertFalse($this->adapter->validateWebhookEvent(
            $payload,
            $this->signJwt($header, $this->deliveryClaims($payload), $otherSecretKey),
            $publicKey,
        ));

        // The token binds to the exact body
        $this->assertFalse($this->adapter->validateWebhookEvent($payload . ' ', 'Bearer ' . $token, $publicKey));

        // A token outlives nothing: expired, or without a lifetime
        $this->assertFalse($this->adapter->validateWebhookEvent(
            $payload,
            $this->signJwt($header, ['exp' => time() - 300] + $this->deliveryClaims($payload), $secretKey),
            $publicKey,
        ));
        $claims = $this->deliveryClaims($payload);
        unset($claims['exp']);
        $this->assertFalse($this->adapter->validateWebhookEvent($payload, $this->signJwt($header, $claims, $secretKey), $publicKey));

        // A token without a body digest binds to nothing
        $claims = $this->deliveryClaims($payload);
        unset($claims['body_sha256']);
        $this->assertFalse($this->adapter->validateWebhookEvent($payload, $this->signJwt($header, $claims, $secretKey), $publicKey));

        // Only EdDSA is ever accepted
        $this->assertFalse($this->adapter->validateWebhookEvent(
            $payload,
            $this->signJwt(['alg' => 'HS256'] + $header, $this->deliveryClaims($payload), $secretKey),
            $publicKey,
        ));
    }

    public function testValidateWebhookEventAcceptsPemPublicKey(): void
    {
        $keyPair = sodium_crypto_sign_keypair();

        // SPKI wrapping of a raw Ed25519 public key
        $der = hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($keyPair);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END PUBLIC KEY-----';

        $payload = '{"delivery_id":"dlv_0123456789"}';
        $token = $this->signJwt(['alg' => 'EdDSA', 'kid' => 'k1'], $this->deliveryClaims($payload), sodium_crypto_sign_secretkey($keyPair));

        $this->assertTrue($this->adapter->validateWebhookEvent($payload, 'Bearer ' . $token, $pem));
    }

    public function testGetWebhookClaims(): void
    {
        $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $payload = '{}';
        $token = $this->signJwt(['alg' => 'EdDSA', 'kid' => 'k1'], $this->deliveryClaims($payload), $secretKey);

        $claims = $this->adapter->getWebhookClaims('Bearer ' . $token);

        $this->assertSame(self::CELL, $claims['iss']);
        $this->assertSame(self::PLUGIN, $claims['aud']);
        $this->assertSame('k1', $claims['kid']);

        $this->expectException(\Exception::class);
        $this->adapter->getWebhookClaims('Bearer not-a-token');
    }

    public function testIsTrustedIssuer(): void
    {
        $this->assertTrue($this->adapter->isTrustedIssuer('https://aws-us-east-2.api.entire.io'));
        $this->assertTrue($this->adapter->isTrustedIssuer('https://aws-eu-central-1.api.entire.io/'));
        $this->assertTrue($this->adapter->isTrustedIssuer('https://entire.io'));

        // Another host, a lookalike, plaintext, or a path are all refused
        $this->assertFalse($this->adapter->isTrustedIssuer('https://evil.example'));
        $this->assertFalse($this->adapter->isTrustedIssuer('https://entire.io.evil.example'));
        $this->assertFalse($this->adapter->isTrustedIssuer('https://evilentire.io'));
        $this->assertFalse($this->adapter->isTrustedIssuer('http://aws-us-east-2.api.entire.io'));
        $this->assertFalse($this->adapter->isTrustedIssuer('https://aws-us-east-2.api.entire.io/cells'));
        $this->assertFalse($this->adapter->isTrustedIssuer(''));

        // A local development core trusts its own plaintext host
        $this->adapter->setEndpoint('http://localhost:8787');
        $this->assertTrue($this->adapter->isTrustedIssuer('http://localhost:8788'));
        $this->assertFalse($this->adapter->isTrustedIssuer('http://entire.io'));
    }

    public function testGetInstallUrl(): void
    {
        $url = $this->adapter->getInstallUrl(
            self::PLUGIN,
            'https://appwrite.test/v1/vcs/entire/callback',
            '{"projectId":"p1"}',
        );

        $parsed = parse_url($url);
        parse_str($parsed['query'] ?? '', $params);

        $this->assertSame('https', $parsed['scheme'] ?? '');
        $this->assertSame('entire.io', $parsed['host'] ?? '');
        $this->assertSame('/plugins/appwrite/install', $parsed['path'] ?? '');
        $this->assertSame('https://appwrite.test/v1/vcs/entire/callback', $params['redirect_uri'] ?? '');
        $this->assertSame('{"projectId":"p1"}', $params['state'] ?? '');

        $this->assertSame('https://entire.io/plugins/appwrite/install', $this->adapter->getInstallUrl(self::PLUGIN));
    }

    public function testVerifyReceipt(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keyPair)), '+/', '-_'), '=');

        $adapter = new FixtureKeysEntire(new Cache(new None()));
        $adapter->setIssuer(self::ISSUER);
        $adapter->fixtureKeys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $publicKey]];

        $header = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'entire-install-receipt+jwt'];
        $claims = [
            'iss' => self::CORE,
            'aud' => [self::ISSUER],
            'plugin_id' => self::PLUGIN,
            'installation_id' => self::INSTALLATION_ID,
            'project' => self::OWNER,
            'project_id' => '01M4ZC35447W79310H702K9276',
            'state' => '{"projectId":"p1"}',
            'jti' => 'rcp_0123456789',
            'iat' => time(),
            'exp' => time() + 300,
        ];

        $verified = $adapter->verifyReceipt($this->signJwt($header, $claims, $secretKey), self::PLUGIN);

        $this->assertSame(self::INSTALLATION_ID, $verified['installation_id']);
        $this->assertSame('{"projectId":"p1"}', $verified['state']);
        $this->assertSame(self::OWNER, $verified['project']);
        $this->assertSame('rcp_0123456789', $verified['jti']);

        // The audience may be a single string
        $this->assertSame(self::INSTALLATION_ID, $adapter->verifyReceipt($this->signJwt($header, ['aud' => self::ISSUER] + $claims, $secretKey), self::PLUGIN)['installation_id']);
    }

    public function testVerifyReceiptRejections(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keyPair)), '+/', '-_'), '=');

        $adapter = new FixtureKeysEntire(new Cache(new None()));
        $adapter->setIssuer(self::ISSUER);
        $adapter->fixtureKeys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $publicKey]];

        $header = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'entire-install-receipt+jwt'];
        $claims = [
            'iss' => self::CORE,
            'aud' => [self::ISSUER],
            'plugin_id' => self::PLUGIN,
            'installation_id' => self::INSTALLATION_ID,
            'iat' => time(),
            'exp' => time() + 300,
        ];

        $cases = [
            'not a JWT' => ['not-a-jwt', self::PLUGIN],
            'wrong algorithm' => [$this->signJwt(['alg' => 'HS256'] + $header, $claims, $secretKey), self::PLUGIN],
            'wrong token type' => [$this->signJwt(['typ' => 'JWT'] + $header, $claims, $secretKey), self::PLUGIN],
            'unknown key id' => [$this->signJwt(['kid' => 'k2'] + $header, $claims, $secretKey), self::PLUGIN],
            'another plugin' => [$this->signJwt($header, $claims, $secretKey), 'other-plugin'],
            'another audience' => [$this->signJwt($header, ['aud' => ['https://other.test']] + $claims, $secretKey), self::PLUGIN],
            'issuer not a core' => [$this->signJwt($header, ['iss' => 'https://evil.test'] + $claims, $secretKey), self::PLUGIN],
            'issuer a lookalike' => [$this->signJwt($header, ['iss' => 'https://eu.auth.entire.io.evil.test'] + $claims, $secretKey), self::PLUGIN],
            'expired' => [$this->signJwt($header, ['exp' => time() - 300] + $claims, $secretKey), self::PLUGIN],
            'missing installation id' => [$this->signJwt($header, ['installation_id' => ''] + $claims, $secretKey), self::PLUGIN],
            'signed by a different key' => [$this->signJwt($header, $claims, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), self::PLUGIN],
        ];

        foreach ($cases as $name => [$receipt, $plugin]) {
            try {
                $adapter->verifyReceipt($receipt, $plugin);
                $this->fail("Expected the '{$name}' receipt to be rejected");
            } catch (\Exception $e) {
                $this->assertNotEmpty($e->getMessage(), $name);
            }
        }
    }

    public function testIsTrustedCore(): void
    {
        foreach (Entire::CORES as $core) {
            $this->assertTrue($this->adapter->isTrustedCore($core));
        }
        $this->assertTrue($this->adapter->isTrustedCore('https://us.auth.entire.io/'));
        $this->assertFalse($this->adapter->isTrustedCore('https://us.console.entire.io'));
        $this->assertFalse($this->adapter->isTrustedCore('https://aws-us-east-2.api.entire.io'));
        $this->assertFalse($this->adapter->isTrustedCore(''));

        // A development core configured as the endpoint is its own core
        $this->adapter->setEndpoint('http://localhost:8787');
        $this->assertTrue($this->adapter->isTrustedCore('http://localhost:8787'));
    }

    public function testGetSigningKeys(): void
    {
        $adapter = new FixtureKeysEntire(new Cache(new None()));
        $adapter->fixtureKeys = [
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => 'first-key'],
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k2', 'x' => 'second-key'],
        ];

        $this->assertSame(['first-key', 'second-key'], $adapter->getSigningKeys());
        $this->assertSame(['first-key', 'second-key'], $adapter->getSigningKeys(self::CELL));
    }

    /**
     * Build a delivery the way Entire wraps a ref change: an envelope naming
     * the installation and the delivering cell, whose event payload carries
     * the ref, both tips and the repository.
     *
     * @return array<string, mixed>
     */
    protected function refPayload(string $type, string $ref): array
    {
        $created = $type === Entire::EVENT_REF_CREATED;
        $deleted = $type === Entire::EVENT_REF_DELETED;

        return [
            'delivery_id' => 'dlv_0123456789',
            'installation_id' => self::INSTALLATION_ID,
            'plugin_id' => self::PLUGIN,
            'api_url' => self::CELL,
            'core_url' => 'https://us.console.entire.io',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => $type,
                'time' => '2026-10-15T10:00:00Z',
                'payload' => [
                    'ref' => $ref,
                    'before' => $created ? str_repeat('0', 40) : self::PREVIOUS_COMMIT_HASH,
                    'after' => $deleted ? str_repeat('0', 40) : self::COMMIT_HASH,
                    'forced' => false,
                    'pusher' => ['account' => ['id' => 'acc_0123456789', 'handle' => 'github:alice']],
                    'repository' => [
                        'id' => self::REPOSITORY_ID,
                        'path' => 'et/' . self::OWNER . '/' . self::REPOSITORY_NAME,
                        'default_branch' => 'main',
                        'clone_url' => 'https://aws-us-east-2.entire.io/et/' . self::OWNER . '/' . self::REPOSITORY_NAME,
                        'entire_clone_url' => 'entire://aws-us-east-2.entire.io/et/' . self::OWNER . '/' . self::REPOSITORY_NAME,
                    ],
                ],
            ],
        ];
    }

    public function testGetEventType(): void
    {
        $this->assertSame('ref.updated', $this->adapter->getEventType((string) json_encode($this->refPayload('ref.updated', 'refs/heads/main'))));
        $this->assertSame('', $this->adapter->getEventType('{}'));

        $this->expectException(\Exception::class);
        $this->adapter->getEventType('not json');
    }

    public function testGetEventRefUpdated(): void
    {
        $events = $this->adapter->getEvents('ref.updated', (string) json_encode($this->refPayload('ref.updated', 'refs/heads/main')));

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('main', $event['branch']);
        $this->assertSame(self::REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::REPOSITORY_NAME, $event['repositoryName']);
        $this->assertSame(self::OWNER, $event['owner']);
        $this->assertSame(self::INSTALLATION_ID, $event['installationId']);
        $this->assertSame('dlv_0123456789', $event['deliveryId']);
        $this->assertSame(self::CELL, $event['apiUrl']);
        $this->assertSame(self::COMMIT_HASH, $event['commitHash']);
        $this->assertSame(self::PREVIOUS_COMMIT_HASH, $event['previousCommitHash']);
        $this->assertSame('main', $event['defaultBranch']);
        $this->assertSame('github:alice', $event['headCommitAuthorName']);
        $this->assertFalse($event['branchCreated']);
        $this->assertFalse($event['branchDeleted']);
        $this->assertFalse($event['forced']);
        $this->assertFalse($event['external']);
        // Deliveries carry no file lists
        $this->assertSame([], $event['affectedFiles']);
        $this->assertSame('https://entire.io/et/test-project/test-repo', $event['repositoryUrl']);
        $this->assertSame('https://entire.io/et/test-project/test-repo/tree/main', $event['branchUrl']);
        $this->assertSame('https://entire.io/et/test-project/test-repo/commit/' . self::COMMIT_HASH, $event['headCommitUrl']);
    }

    public function testGetEventRefLifecycle(): void
    {
        $created = $this->adapter->getEvents('ref.created', (string) json_encode($this->refPayload('ref.created', 'refs/heads/new-branch')));
        $this->assertTrue($created[0]['branchCreated']);
        $this->assertFalse($created[0]['branchDeleted']);
        $this->assertSame('new-branch', $created[0]['branch']);

        $deleted = $this->adapter->getEvents('ref.deleted', (string) json_encode($this->refPayload('ref.deleted', 'refs/heads/old-branch')));
        $this->assertFalse($deleted[0]['branchCreated']);
        $this->assertTrue($deleted[0]['branchDeleted']);
        $this->assertSame('', $deleted[0]['commitHash']);
        $this->assertSame('', $deleted[0]['headCommitUrl']);
    }

    public function testGetEventIgnoresTags(): void
    {
        // Only branches deploy; a tag ref is not a push event
        $this->assertSame([], $this->adapter->getEvents('ref.created', (string) json_encode($this->refPayload('ref.created', 'refs/tags/v1.0.0'))));
    }

    public function testGetEventReadsTypeFromBody(): void
    {
        // The body's event type is authoritative over whatever the caller passes
        $events = $this->adapter->getEvents('', (string) json_encode($this->refPayload('ref.deleted', 'refs/heads/main')));

        $this->assertCount(1, $events);
        $this->assertTrue($events[0]['branchDeleted']);
    }

    public function testGetEventInstallation(): void
    {
        $payload = (string) json_encode([
            'delivery_id' => 'dlv_0123456789',
            'installation_id' => self::INSTALLATION_ID,
            'plugin_id' => self::PLUGIN,
            'api_url' => self::CELL,
            'core_url' => 'https://us.console.entire.io',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'installation.deleted',
                'time' => '2026-10-15T10:00:00Z',
                'payload' => [
                    'installation' => [
                        'project_id' => 'prj_0123456789',
                        'repository_selection' => 'all',
                        'repository_count' => 0,
                        'revision' => 3,
                    ],
                ],
            ],
        ]);

        $events = $this->adapter->getEvents('installation.deleted', $payload);

        $this->assertCount(1, $events);
        $this->assertSame('deleted', $events[0]['action']);
        $this->assertSame(self::INSTALLATION_ID, $events[0]['installationId']);
        $this->assertSame('prj_0123456789', $events[0]['projectId']);
        $this->assertSame('all', $events[0]['repositorySelection']);
    }

    public function testGetEventsIgnoresUnknownTypes(): void
    {
        $payload = $this->refPayload('trail.opened', 'refs/heads/main');

        $this->assertSame([], $this->adapter->getEvents('trail.opened', (string) json_encode($payload)));
    }

    public function testGetEventsRejectsInvalidPayload(): void
    {
        $this->expectException(\Exception::class);
        $this->adapter->getEvents('ref.updated', 'not json');
    }

    public function testUrls(): void
    {
        $this->assertSame('https://entire.io/et/acme', $this->adapter->getOrganizationUrl('acme'));
        $this->assertSame('https://entire.io/et/acme/api', $this->adapter->getRepositoryUrl('acme', 'api'));
        $this->assertSame('https://entire.io/et/acme/api/tree/main', $this->adapter->getBranchUrl('acme', 'api', 'main'));
        $this->assertSame('https://entire.io/et/acme/api/commit/abc', $this->adapter->getCommitUrl('acme', 'api', 'abc'));
        $this->assertSame('https://entire.io/et/acme/api/blob/main', $this->adapter->getFileUrl('acme', 'api', 'main'));
        $this->assertSame('https://entire.io/et/acme/api/trails/7', $this->adapter->getPullRequestUrl('acme', 'api', 7));
    }

    public function testCapabilities(): void
    {
        $this->assertSame('entire', $this->adapter->getName());
        $this->assertSame([Entire::WEBHOOK_SCOPE_INSTALLATION], $this->adapter->getSupportedWebhookScopes());
        $this->assertSame('authorization', $this->adapter->getSignatureHeaderName());
        $this->assertFalse($this->adapter->supportsRepositoryCreation());
        $this->assertFalse($this->adapter->supportsRepositoryDeletion());
        $this->assertFalse($this->adapter->supportsRepositoryArchives());
        $this->assertFalse($this->adapter->supportsCommentImages());
        $this->assertFalse($this->adapter->supportsPublicRepositories());
        $this->assertFalse($this->adapter->hasAccessToAllRepositories());
        $this->assertSame([], $this->adapter->listRepositoryLanguages('acme', 'api'));

        // Only git-remote-entire fetches an entire:// address, so a consumer
        // clones through generateCloneCommand() and never with headers
        $this->assertFalse($this->adapter->supportsRepositoryCloneUrl());
        $this->assertSame([], $this->adapter->getRepositoryCloneHeaders());

        // Without a token, a push is a plain push
        $this->assertSame("'git' '-C' '/tmp/clone' 'push' 'origin' 'main'", $this->adapter->generatePushCommand('acme', 'api', '/tmp/clone', 'main')->toString());
    }

    public function testCheckRunsStates(): void
    {
        $adapter = new CheckRunsEntire(new Cache(new None()));

        $queued = $adapter->write('queued', 'Site (Project)', 'Starting...');
        $this->assertSame('main', $queued['branch']);
        $run = $queued['check_runs'][0];
        $this->assertSame('queued', $run['status']);
        $this->assertArrayNotHasKey('conclusion', $run);
        $this->assertArrayNotHasKey('completed_at', $run);
        $this->assertSame('Site (Project)', $run['key']);
        $this->assertSame('Site (Project)', $run['name']);
        $this->assertSame('Site (Project)', $run['external_id']);
        $this->assertSame(['title' => 'Starting...', 'summary' => 'Starting...'], $run['output']);
        // The suite reports the same state next to its timestamp, and no output
        $this->assertSame('queued', $queued['check_suite']['status']);
        $this->assertSame($run['external_updated_at'], $queued['check_suite']['external_updated_at']);
        $this->assertArrayNotHasKey('output', $queued['check_suite']);

        $this->assertSame('in_progress', $adapter->write('pending', 'check', 'Building...')['check_runs'][0]['status']);
        $this->assertSame('in_progress', $adapter->write('in_progress', 'check', 'Building...')['check_runs'][0]['status']);

        foreach (['success' => 'success', 'failure' => 'failure', 'error' => 'failure', 'cancelled' => 'cancelled'] as $state => $conclusion) {
            $body = $adapter->write($state, 'check', 'Done.');
            $run = $body['check_runs'][0];
            $this->assertSame('completed', $run['status'], $state);
            $this->assertSame($conclusion, $run['conclusion'], $state);
            $this->assertArrayHasKey('completed_at', $run, $state);
            $this->assertSame($conclusion, $body['check_suite']['conclusion'], $state);
        }
    }

    public function testCheckRunsOutputAndDetails(): void
    {
        $adapter = new CheckRunsEntire(new Cache(new None()));

        $summary = "- Site best in project QA\n- Preview: https://x.example.com";
        $body = $adapter->write('success', 'check', 'Build succeeded.', $summary, 'https://x.example.com');
        $run = $body['check_runs'][0];
        $this->assertSame(['title' => 'Build succeeded.', 'summary' => $summary], $run['output']);
        $this->assertSame('https://x.example.com', $run['details_url']);
        $this->assertSame('https://x.example.com', $body['check_suite']['details_url']);

        // Only an https URL without credentials reaches Entire
        $this->assertArrayNotHasKey('details_url', $adapter->write('success', 'check', 'Done.', '', 'http://localhost/console')['check_runs'][0]);
        $this->assertArrayNotHasKey('details_url', $adapter->write('success', 'check', 'Done.', '', 'https://user:secret@example.com')['check_runs'][0]);

        // No name falls to the default key; no title means no output
        $plain = $adapter->write('pending', '', '');
        $this->assertSame('default', $plain['check_runs'][0]['key']);
        $this->assertArrayNotHasKey('output', $plain['check_runs'][0]);
    }

    public function testCreateFileRejectsEscapingPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('escapes the repository');
        $this->adapter->createFile('owner', 'repo', '../escape.txt', 'content');
    }

    public function testCreateFileRejectsEmptyPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('names no file');
        $this->adapter->createFile('owner', 'repo', './.', 'content');
    }

    public function testLiveAdapterSuite(): void
    {
        $this->markTestSkipped(
            'Entire cannot run the shared live suite: a plugin installation token cannot create or delete repositories, so fixture repositories can neither be provisioned nor cleaned up.',
        );
    }
}
