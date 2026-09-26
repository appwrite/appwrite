<?php

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Origin;

/**
 * Origin whose JWKS is a local fixture, so receipt verification runs the
 * real code path - key lookup included - without touching the network.
 */
class FixtureKeysOrigin extends Origin
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $fixtureKeys = [];

    #[\Override]
    protected function jwks(bool $refresh = false): array
    {
        return $this->fixtureKeys;
    }
}

/**
 * Exercises everything Origin can prove without credentials: webhook
 * signature verification and delivery parsing, which never touch the
 * network. The live half of the shared adapter suite cannot run at all -
 * see testLiveAdapterSuite().
 */
final class OriginTest extends TestCase
{
    protected const string REPOSITORY_ID = 'repo_0123456789';
    protected const string REPOSITORY_NAME = 'test-repo';
    protected const string OWNER = 'test-owner';
    protected const string COMMIT_HASH = 'def4567890def4567890def4567890def4567890';
    protected const string COMMIT_MESSAGE = 'Test commit message';
    protected const string AUTHOR_NAME = 'Test Author';
    protected const string AUTHOR_EMAIL = 'author@example.com';
    protected const string HEAD_BRANCH = 'feature-branch';
    protected const int PULL_REQUEST_NUMBER = 42;

    protected Origin $adapter;

    /**
     * Verification and parsing need no installation, so the adapter stays
     * uninitialized - initializeVariables() would reach for the network.
     */
    protected function setUp(): void
    {
        $this->adapter = new Origin(new Cache(new None()));
    }

    /**
     * Sign a payload the way Origin signs its deliveries: an Ed25519
     * signature over the lowercase hex SHA-256 digest of
     * "<id>.<timestamp>.<body>". $secretKey is a libsodium secret key.
     *
     * @param non-empty-string $secretKey
     */
    protected function signWebhookPayload(string $payload, string $secretKey): string
    {
        return 'v1ed,' . base64_encode(sodium_crypto_sign_detached(hash('sha256', $payload), $secretKey));
    }

    public function testValidateWebhookEvent(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $payload = 'whd_0123456789.1755500000.{"deliveryId":"whd_0123456789"}';
        $signature = $this->signWebhookPayload($payload, $secretKey);

        $this->assertTrue($this->adapter->validateWebhookEvent($payload, $signature, $publicKey));
        $this->assertFalse($this->adapter->validateWebhookEvent($payload, 'not-the-signature', $publicKey));

        // A signature by a different key must not verify
        $otherSecretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->assertFalse(
            $this->adapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $otherSecretKey), $publicKey),
        );

        // Tampered content must not verify either
        $this->assertFalse($this->adapter->validateWebhookEvent($payload . 'tampered', $signature, $publicKey));

        // During key rotation the header carries several space-separated
        // signatures, and any one of them verifying is enough
        $this->assertTrue($this->adapter->validateWebhookEvent(
            $payload,
            $this->signWebhookPayload($payload, $otherSecretKey) . ' ' . $signature,
            $publicKey,
        ));
    }

    public function testValidateWebhookEventAcceptsPemPublicKey(): void
    {
        $keyPair = sodium_crypto_sign_keypair();

        // SPKI wrapping of a raw Ed25519 public key
        $der = hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($keyPair);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . '-----END PUBLIC KEY-----';

        $payload = 'whd_0123456789.1755500000.{"deliveryId":"whd_0123456789"}';
        $signature = $this->signWebhookPayload($payload, sodium_crypto_sign_secretkey($keyPair));

        $this->assertTrue($this->adapter->validateWebhookEvent($payload, $signature, $pem));
    }

    /**
     * Build a delivery the way Origin wraps a push: an envelope whose event
     * payload carries the repository reference and one ref update per
     * pushed ref.
     *
     * @return array<string, mixed>
     */
    protected function pushPayload(string $branch, bool $created = false, bool $deleted = false): array
    {
        return [
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'repository.pushed',
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'repository' => [
                        'id' => self::REPOSITORY_ID,
                        'name' => self::REPOSITORY_NAME,
                        'owner' => ['slug' => self::OWNER, 'id' => 'ns_0123456789'],
                    ],
                    'refUpdates' => [[
                        'ref' => 'refs/heads/' . $branch,
                        'before' => $created ? str_repeat('0', 40) : 'abc123',
                        'after' => $deleted ? str_repeat('0', 40) : self::COMMIT_HASH,
                        'created' => $created,
                        'deleted' => $deleted,
                        'forced' => false,
                        'headCommit' => $deleted ? null : [
                            'sha' => self::COMMIT_HASH,
                            'author' => ['name' => self::AUTHOR_NAME, 'email' => self::AUTHOR_EMAIL],
                            'committer' => ['name' => self::AUTHOR_NAME, 'email' => self::AUTHOR_EMAIL],
                            'message' => self::COMMIT_MESSAGE,
                        ],
                    ]],
                    'refUpdatesCount' => 1,
                    'pushedAt' => '2026-08-18T10:00:00Z',
                    'pusher' => ['user' => ['id' => 'user_0123456789', 'email' => self::AUTHOR_EMAIL]],
                ],
            ],
        ];
    }

    /**
     * Build a delivery the way Origin announces a pull request event. The
     * event type carries the action; there is no separate action field.
     *
     * @return array<string, mixed>
     */
    protected function pullRequestPayload(string $type = 'pull_request.created'): array
    {
        return [
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => $type,
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'pullRequest' => [
                        'id' => 'pr_0123456789',
                        'number' => (string) self::PULL_REQUEST_NUMBER,
                        'state' => 'open',
                        'draft' => false,
                        'merged' => false,
                        'title' => 'Test PR',
                        'body' => '',
                        'head' => ['ref' => 'refs/heads/' . self::HEAD_BRANCH, 'sha' => self::COMMIT_HASH],
                        'base' => ['ref' => 'refs/heads/main', 'sha' => 'abc123'],
                        'author' => ['user' => ['id' => 'user_0123456789', 'email' => self::AUTHOR_EMAIL]],
                    ],
                    'repository' => [
                        'id' => self::REPOSITORY_ID,
                        'name' => self::REPOSITORY_NAME,
                        'owner' => ['slug' => self::OWNER, 'id' => 'ns_0123456789'],
                    ],
                ],
            ],
        ];
    }

    public function testGetInstallUrl(): void
    {
        $url = $this->adapter->getInstallUrl(
            'app_0123456789',
            ['repository:contents:read', 'repository:checks:write'],
            'https://appwrite.test/v1/vcs/origin/callback',
            '{"projectId":"p1"}',
        );

        $parsed = parse_url($url);
        parse_str($parsed['query'] ?? '', $params);

        $this->assertSame('https', $parsed['scheme'] ?? '');
        $this->assertSame('cursor.com', $parsed['host'] ?? '');
        $this->assertSame('/codebase/apps/install', $parsed['path'] ?? '');
        $this->assertSame('app_0123456789', $params['client_id'] ?? '');
        $this->assertSame('repository:contents:read repository:checks:write', $params['scope'] ?? '');
        $this->assertSame('https://appwrite.test/v1/vcs/origin/callback', $params['redirect_uri'] ?? '');
        $this->assertSame('{"projectId":"p1"}', $params['state'] ?? '');
        $this->assertArrayNotHasKey('source', $params);
    }

    public function testGetInstallUrlWithoutScopesReadsAppMetadata(): void
    {
        // The install page refuses an explicit empty scope list unless told
        // to read the scopes from the app's registered metadata.
        parse_str(parse_url($this->adapter->getInstallUrl('app_0123456789'), PHP_URL_QUERY) ?: '', $params);

        $this->assertSame('app-metadata', $params['source'] ?? '');
        $this->assertArrayNotHasKey('scope', $params);
        $this->assertArrayNotHasKey('redirect_uri', $params);
        $this->assertArrayNotHasKey('state', $params);
    }

    /**
     * Encode a receipt JWT the way Cursor does: EdDSA over
     * "<base64url header>.<base64url claims>". $secretKey is a libsodium
     * secret key.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     * @param non-empty-string $secretKey
     */
    protected function signReceipt(array $header, array $claims, string $secretKey): string
    {
        $encode = fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $signingInput = $encode(json_encode($header) ?: '') . '.' . $encode(json_encode($claims) ?: '');

        return $signingInput . '.' . $encode(sodium_crypto_sign_detached($signingInput, $secretKey));
    }

    public function testVerifyReceipt(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keyPair)), '+/', '-_'), '=');

        $adapter = new FixtureKeysOrigin(new Cache(new None()));
        $adapter->fixtureKeys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $publicKey]];

        $header = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'origin-installation-receipt+jwt'];
        $claims = [
            'iss' => 'https://api.cursor.com/v1/origin',
            'aud' => 'app_0123456789',
            'sub' => 'i_0123456789',
            'state' => '{"projectId":"p1"}',
            'namespace_id' => 'ns_0123456789',
            'iat' => time(),
            'exp' => time() + 300,
        ];

        $verified = $adapter->verifyReceipt($this->signReceipt($header, $claims, $secretKey), 'app_0123456789');

        $this->assertSame('i_0123456789', $verified['sub']);
        $this->assertSame('{"projectId":"p1"}', $verified['state']);
        $this->assertSame('ns_0123456789', $verified['namespace_id']);
    }

    public function testVerifyReceiptRejections(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keyPair)), '+/', '-_'), '=');

        $adapter = new FixtureKeysOrigin(new Cache(new None()));
        $adapter->fixtureKeys = [['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => $publicKey]];

        $header = ['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'origin-installation-receipt+jwt'];
        $claims = [
            'iss' => 'https://api.cursor.com/v1/origin',
            'aud' => 'app_0123456789',
            'sub' => 'i_0123456789',
            'iat' => time(),
            'exp' => time() + 300,
        ];

        $cases = [
            'not a JWT' => ['not-a-jwt', 'app_0123456789'],
            'wrong algorithm' => [$this->signReceipt(['alg' => 'HS256'] + $header, $claims, $secretKey), 'app_0123456789'],
            'wrong token type' => [$this->signReceipt(['typ' => 'other+jwt'] + $header, $claims, $secretKey), 'app_0123456789'],
            'unknown key id' => [$this->signReceipt(['kid' => 'k2'] + $header, $claims, $secretKey), 'app_0123456789'],
            'wrong audience' => [$this->signReceipt($header, $claims, $secretKey), 'app_other'],
            'wrong issuer' => [$this->signReceipt($header, ['iss' => 'https://evil.test'] + $claims, $secretKey), 'app_0123456789'],
            'expired' => [$this->signReceipt($header, ['exp' => time() - 300] + $claims, $secretKey), 'app_0123456789'],
            'missing installation id' => [$this->signReceipt($header, ['sub' => ''] + $claims, $secretKey), 'app_0123456789'],
            'signed by a different key' => [$this->signReceipt($header, $claims, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), 'app_0123456789'],
        ];

        foreach ($cases as $name => [$receipt, $appId]) {
            try {
                $adapter->verifyReceipt($receipt, $appId);
                $this->fail("Expected the '{$name}' receipt to be rejected");
            } catch (\Exception $e) {
                $this->assertNotEmpty($e->getMessage(), $name);
            }
        }
    }

    public function testGetSigningKeys(): void
    {
        $adapter = new FixtureKeysOrigin(new Cache(new None()));
        $adapter->fixtureKeys = [
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k1', 'x' => 'first-key'],
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => 'k2', 'x' => 'second-key'],
        ];

        $this->assertSame(['first-key', 'second-key'], $adapter->getSigningKeys());
    }

    public function testGetEventPush(): void
    {
        $events = $this->adapter->getEvents('repository.pushed', (string) json_encode($this->pushPayload('main')));

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('main', $event['branch']);
        $this->assertSame(self::REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::REPOSITORY_NAME, $event['repositoryName']);
        $this->assertSame(self::OWNER, $event['owner']);
        $this->assertSame('i_0123456789', $event['installationId']);
        $this->assertSame(self::COMMIT_HASH, $event['commitHash']);
        $this->assertSame(self::COMMIT_MESSAGE, $event['headCommitMessage']);
        $this->assertSame(self::AUTHOR_NAME, $event['headCommitAuthorName']);
        $this->assertSame(self::AUTHOR_EMAIL, $event['headCommitAuthorEmail']);
        $this->assertFalse($event['branchCreated']);
        $this->assertFalse($event['branchDeleted']);
        $this->assertFalse($event['external']);
        // Origin push deliveries carry no per-commit file lists
        $this->assertSame([], $event['affectedFiles']);
        $this->assertStringContainsString(self::REPOSITORY_NAME, \strval($event['repositoryUrl']));
        $this->assertNotEmpty($event['branchUrl']);
        $this->assertNotEmpty($event['headCommitUrl']);
    }

    public function testGetEventPushBranchLifecycle(): void
    {
        $created = $this->adapter->getEvents('repository.pushed', (string) json_encode($this->pushPayload('new-branch', created: true)));
        $this->assertTrue($created[0]['branchCreated']);
        $this->assertFalse($created[0]['branchDeleted']);

        $deleted = $this->adapter->getEvents('repository.pushed', (string) json_encode($this->pushPayload('old-branch', deleted: true)));
        $this->assertFalse($deleted[0]['branchCreated']);
        $this->assertTrue($deleted[0]['branchDeleted']);
    }

    public function testGetEventPushWithMultipleRefUpdates(): void
    {
        $payload = $this->pushPayload('main');

        $secondRef = $payload['event']['payload']['refUpdates'][0];
        $secondRef['ref'] = 'refs/heads/feature-branch';
        $payload['event']['payload']['refUpdates'][] = $secondRef;
        $payload['event']['payload']['refUpdatesCount'] = 2;

        $events = $this->adapter->getEvents('repository.pushed', (string) json_encode($payload));

        $this->assertCount(2, $events);
        $this->assertSame('main', $events[0]['branch']);
        $this->assertSame('feature-branch', $events[1]['branch']);
    }

    public function testGetEventPullRequest(): void
    {
        $events = $this->adapter->getEvents('pull_request.created', (string) json_encode($this->pullRequestPayload()));

        $this->assertCount(1, $events);
        $event = $events[0];

        $this->assertSame('opened', $event['action']);
        $this->assertSame(self::PULL_REQUEST_NUMBER, $event['pullRequestNumber']);
        $this->assertSame(self::HEAD_BRANCH, $event['branch']);
        $this->assertSame(self::REPOSITORY_ID, $event['repositoryId']);
        $this->assertSame(self::OWNER, $event['owner']);
        $this->assertSame(self::COMMIT_HASH, $event['commitHash']);

        // Origin pull requests always open from a branch of the same
        // repository - there is no fork model - so none is ever external
        $this->assertFalse($event['external']);
    }

    public function testGetEventPullRequestMapsLifecycleActions(): void
    {
        $expected = [
            'pull_request.created' => 'opened',
            'pull_request.head_ref.pushed' => 'synchronize',
            'pull_request.base_ref.updated' => 'edited',
            'pull_request.metadata.updated' => 'edited',
            'pull_request.closed' => 'closed',
            'pull_request.merged' => 'closed',
            'pull_request.reopened' => 'reopened',
            'pull_request.published' => 'ready_for_review',
        ];

        foreach ($expected as $type => $action) {
            $events = $this->adapter->getEvents($type, (string) json_encode($this->pullRequestPayload($type)));
            $this->assertCount(1, $events);
            $this->assertSame($action, $events[0]['action'], "Unexpected action for {$type}");
        }

        // Comment, review and reviewer deliveries are not lifecycle events
        $type = 'pull_request.comment.created';
        $this->assertSame([], $this->adapter->getEvents($type, (string) json_encode($this->pullRequestPayload($type))));
    }

    public function testGetEventInstallation(): void
    {
        $payload = (string) json_encode([
            'deliveryId' => 'whd_0123456789',
            'appId' => 'app_0123456789',
            'installationId' => 'i_0123456789',
            'event' => [
                'id' => 'evt_0123456789',
                'type' => 'installation.deleted',
                'eventTime' => '2026-08-18T10:00:00Z',
                'payload' => [
                    'installation' => [
                        'id' => 'i_0123456789',
                        'target' => ['slug' => 'test-workspace', 'id' => 'ns_0123456789'],
                        'repoSelectionMode' => 'all',
                    ],
                    'app' => ['id' => 'app_0123456789', 'slug' => 'test-app'],
                ],
            ],
        ]);

        $events = $this->adapter->getEvents('installation.deleted', $payload);

        $this->assertCount(1, $events);
        $this->assertSame('deleted', $events[0]['action']);
        $this->assertSame('i_0123456789', $events[0]['installationId']);
        $this->assertSame('test-workspace', $events[0]['userName']);
    }

    public function testGetEventsRejectsInvalidPayload(): void
    {
        $this->expectException(\Exception::class);
        $this->adapter->getEvents('repository.pushed', 'not json');
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
            'Origin cannot run the shared live suite: the partner API does not allow app installations to create repositories, and it has no repository deletion endpoint, so fixture repositories can neither be provisioned nor cleaned up.',
        );
    }
}
