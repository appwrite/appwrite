<?php

namespace Utopia\VCS\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Console\Command;
use Utopia\VCS\Adapter\Git\Origin;
use Utopia\VCS\Exception\OwnerNotFound;

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
 * Origin whose API calls are recorded and answered per path, so token
 * minting and the credential each call carries can be read back.
 */
class RecordingOrigin extends Origin
{
    /**
     * @var list<array{method: string, path: string, authorization: string}>
     */
    public array $requests = [];

    /**
     * @param array<string, array{int, array<mixed>}> $answers Status and body per path; unlisted paths answer 200 with an empty body
     */
    public function __construct(Cache $cache, private readonly array $answers = [])
    {
        parent::__construct($cache);
    }

    #[\Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'authorization' => $headers['Authorization'] ?? ''];
        [$status, $body] = $this->answers[$path] ?? [200, []];

        return ['headers' => ['status-code' => $status], 'body' => $body];
    }

    public function runCommand(Command $command): string
    {
        return $this->execute($command, 'Running the command');
    }

    public function cloneUrl(string $owner, string $repositoryName): string
    {
        return $this->authenticatedCloneUrl($owner, $repositoryName);
    }
}

/**
 * Origin whose git commands are recorded instead of run, each one outlasting
 * the credentials it started with, and whose every mint hands out a new
 * token, so a remote built once and reused shows up as a stale token.
 */
class SlowGitOrigin extends Origin
{
    /**
     * @var list<string>
     */
    public array $commands = [];

    public int $mints = 0;

    #[\Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        if (!str_ends_with($path, '/access_tokens')) {
            return ['headers' => ['status-code' => 200], 'body' => []];
        }

        $this->mints++;

        return ['headers' => ['status-code' => 201], 'body' => ['token' => 'oit_' . $this->mints]];
    }

    #[\Override]
    protected function execute(Command $command, string $action): string
    {
        $this->commands[] = $command->toString();
        $this->expiresAt = 0;

        return '';
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
        $encode = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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
            } catch (Exception $error) {
                $this->assertNotEmpty($error->getMessage(), $name);

                continue;
            }

            $this->fail("Expected the '{$name}' receipt to be rejected");
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

    public function testCreateFileRejectsGitMetadataPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('names repository metadata');
        $this->adapter->createFile('owner', 'repo', 'docs/../.Git/config', 'content');
    }

    public function testCreateFileRejectsEmptyPath(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('names no file');
        $this->adapter->createFile('owner', 'repo', './.', 'content');
    }

    protected const string INSTALLATION_ID = 'i_0123456789';
    protected const string APP_ID = 'app_0123456789';
    protected const string MINT_PATH = '/app/installations/i_0123456789/access_tokens';

    /**
     * Seconds left on a credential that is close enough to expiry to be replaced
     */
    protected const int EXPIRING_IN = 30;

    /**
     * Sign an app JWT the way the adapter does, expiring at $expiresAt.
     *
     * @param non-empty-string $secretKey
     */
    protected function appJwt(int $expiresAt, string $secretKey): string
    {
        return $this->signReceipt(
            ['alg' => 'EdDSA', 'kid' => self::APP_ID, 'typ' => 'JWT'],
            ['iss' => self::APP_ID, 'aud' => 'origin-apps', 'iat' => $expiresAt - Origin::APP_JWT_EXPIRY, 'exp' => $expiresAt],
            $secretKey,
        );
    }

    /**
     * @return array<mixed>
     */
    protected function jwtClaims(string $jwt): array
    {
        $segments = explode('.', $jwt);
        $this->assertCount(3, $segments, 'The bearer credential is not a JWT');

        $claims = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);
        $this->assertIsArray($claims);

        return $claims;
    }

    protected function bearer(string $authorization): string
    {
        $this->assertStringStartsWith('Bearer ', $authorization);

        return substr($authorization, \strlen('Bearer '));
    }

    /**
     * An adapter initialised from a cache entry holding the given tokens, the
     * way a later request finds what an earlier one minted.
     *
     * @param array<string, mixed> $cached
     * @param array<string, array{int, array<mixed>}> $answers
     */
    protected function cachedOrigin(Cache $cache, array $cached, string $seed, array $answers = []): RecordingOrigin
    {
        $cache->save('origin-' . self::INSTALLATION_ID, (string) json_encode($cached));

        $adapter = new RecordingOrigin($cache, $answers);
        $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode($seed), self::APP_ID);
        $this->assertSame([], $adapter->requests, 'A cache hit must not reach the API');

        return $adapter;
    }

    public function testJwtCallsResignAnExpiredCachedJwt(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
        $expired = $this->appJwt(time() - 10, $secretKey);

        $adapter = $this->cachedOrigin(
            new Cache(new Memory()),
            ['jwtToken' => $expired, 'accessToken' => 'oit_old'],
            $seed,
            [self::MINT_PATH => [201, ['token' => 'oit_new']]],
        );

        $signedAfter = time();
        $adapter->getAuthenticatedApp();

        $this->assertSame(['POST', 'GET'], array_column($adapter->requests, 'method'));
        $this->assertSame([self::MINT_PATH, '/app'], array_column($adapter->requests, 'path'));

        $jwt = $this->bearer($adapter->requests[1]['authorization']);
        $this->assertNotSame($expired, $jwt);
        $this->assertGreaterThanOrEqual($signedAfter + Origin::APP_JWT_EXPIRY, $this->jwtClaims($jwt)['exp'], 'The JWT must be freshly signed');
        $this->assertSame(self::APP_ID, $this->jwtClaims($jwt)['iss']);

        [$header, $claims, $signature] = explode('.', $jwt);
        $this->assertSame(
            rtrim(strtr(base64_encode(sodium_crypto_sign_detached($header . '.' . $claims, $secretKey)), '+/', '-_'), '='),
            $signature,
            'The re-signed JWT must be signed with the app key',
        );
    }

    public function testAccessTokenIsReMintedWhenItsJwtExpires(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $cache = new Cache(new Memory());
        $expiring = $this->appJwt(time() + self::EXPIRING_IN, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));

        $adapter = $this->cachedOrigin(
            $cache,
            ['jwtToken' => $expiring, 'accessToken' => 'oit_old'],
            $seed,
            [self::MINT_PATH => [201, ['token' => 'oit_new']]],
        );

        $signedAfter = time();
        $adapter->getRateLimit();

        $this->assertSame([self::MINT_PATH, '/rate_limit'], array_column($adapter->requests, 'path'));

        $mintJwt = $this->bearer($adapter->requests[0]['authorization']);
        $this->assertNotSame($expiring, $mintJwt, 'The token must be minted with a fresh JWT');
        $this->assertGreaterThanOrEqual($signedAfter + Origin::APP_JWT_EXPIRY, $this->jwtClaims($mintJwt)['exp'], 'The JWT must be freshly signed');
        $this->assertSame('Bearer oit_new', $adapter->requests[1]['authorization']);

        $adapter->getRateLimit();
        $this->assertCount(3, $adapter->requests, 'A fresh token must be reused, not minted again');
        $this->assertSame('Bearer oit_new', $adapter->requests[2]['authorization']);

        $entry = $cache->load('origin-' . self::INSTALLATION_ID, Origin::APP_JWT_EXPIRY);
        $this->assertIsString($entry);
        $cached = json_decode($entry, true);
        $this->assertIsArray($cached);
        $this->assertSame('oit_new', $cached['accessToken'] ?? null, 'The fresh pair must replace the cached one');
        $this->assertSame($mintJwt, $cached['jwtToken'] ?? null);
    }

    public function testAccessTokenHonoursAShorterMintedExpiry(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $adapter = new RecordingOrigin(new Cache(new Memory()), [
            self::MINT_PATH => [201, ['token' => 'oit_short', 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', time() + self::EXPIRING_IN)]],
        ]);
        $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode($seed), self::APP_ID);
        $this->assertCount(1, $adapter->requests);

        $adapter->getRateLimit();

        $this->assertSame([self::MINT_PATH, self::MINT_PATH, '/rate_limit'], array_column($adapter->requests, 'path'));
    }

    public function testFreshCachedTokensAreReusedUnchanged(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $fresh = $this->appJwt(time() + Origin::APP_JWT_EXPIRY, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));

        $adapter = $this->cachedOrigin(
            new Cache(new Memory()),
            ['jwtToken' => $fresh, 'accessToken' => 'oit_cached'],
            $seed,
        );

        $adapter->getAuthenticatedApp();
        $adapter->getRateLimit();

        $this->assertSame(['/app', '/rate_limit'], array_column($adapter->requests, 'path'));
        $this->assertSame('Bearer ' . $fresh, $adapter->requests[0]['authorization']);
        $this->assertSame('Bearer oit_cached', $adapter->requests[1]['authorization']);
    }

    public function testMissingInstallationThrowsOwnerNotFound(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $fresh = $this->appJwt(time() + Origin::APP_JWT_EXPIRY, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));

        $adapter = $this->cachedOrigin(
            new Cache(new Memory()),
            ['jwtToken' => $fresh, 'accessToken' => 'oit_cached'],
            $seed,
            ['/app/installations/' . self::INSTALLATION_ID => [404, ['code' => 'not_found', 'message' => 'installation not found']]],
        );

        try {
            $adapter->getOwnerName(self::INSTALLATION_ID);
            $this->fail('Expected a missing installation to throw OwnerNotFound');
        } catch (OwnerNotFound $error) {
            $this->assertSame(404, $error->getCode());
            $this->assertSame("Installation '" . self::INSTALLATION_ID . "' was not found.", $error->getMessage());
        }
    }

    public function testFailedInstallationLookupNamesTheInstallation(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $fresh = $this->appJwt(time() + Origin::APP_JWT_EXPIRY, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));

        $adapter = $this->cachedOrigin(
            new Cache(new Memory()),
            ['jwtToken' => $fresh, 'accessToken' => 'oit_cached'],
            $seed,
            ['/app/installations/' . self::INSTALLATION_ID => [503, ['code' => 'unavailable']]],
        );

        try {
            $adapter->getOwnerName(self::INSTALLATION_ID);
        } catch (OwnerNotFound) {
            $this->fail('Only a missing installation is OwnerNotFound');
        } catch (Exception $error) {
            $this->assertSame(503, $error->getCode());
            $this->assertSame('Failed to get installation ' . self::INSTALLATION_ID . ': HTTP 503', $error->getMessage());

            return;
        }

        $this->fail('Expected a failed installation lookup to throw');
    }

    public function testRefreshForADeletedInstallationThrowsOwnerNotFound(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $expiring = $this->appJwt(time() + self::EXPIRING_IN, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));

        $adapter = $this->cachedOrigin(
            new Cache(new Memory()),
            ['jwtToken' => $expiring, 'accessToken' => 'oit_old'],
            $seed,
            [self::MINT_PATH => [404, ['code' => 'not_found', 'message' => 'installation not found']]],
        );

        try {
            $adapter->getAuthenticatedApp();
            $this->fail('Expected refreshing the tokens of a deleted installation to throw OwnerNotFound');
        } catch (OwnerNotFound $error) {
            $this->assertSame(404, $error->getCode());
            $this->assertSame("Installation '" . self::INSTALLATION_ID . "' was not found.", $error->getMessage());
        }

        $this->assertSame([self::MINT_PATH], array_column($adapter->requests, 'path'), 'The refresh must stop at the failed mint');
    }

    public function testMintedPairIsCachedWithItsExpiryAndReused(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $cache = new Cache(new Memory());
        $tokenExpiresAt = time() + Origin::APP_JWT_EXPIRY - 100;

        $minting = new RecordingOrigin($cache, [
            self::MINT_PATH => [201, ['token' => 'oit_minted', 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $tokenExpiresAt)]],
        ]);
        $minting->initializeVariables(self::INSTALLATION_ID, base64_encode($seed), self::APP_ID);
        $this->assertSame([self::MINT_PATH], array_column($minting->requests, 'path'));

        $entry = $cache->load('origin-' . self::INSTALLATION_ID, Origin::APP_JWT_EXPIRY);
        $this->assertIsString($entry);
        $cached = json_decode($entry, true);
        $this->assertIsArray($cached);
        $this->assertSame('oit_minted', $cached['accessToken'] ?? null);
        $this->assertSame($tokenExpiresAt, $cached['expiresAt'] ?? null, 'The cache must record when the minted token expires');

        $jwt = $cached['jwtToken'] ?? null;
        $this->assertIsString($jwt);
        $this->assertGreaterThan($tokenExpiresAt, $this->jwtClaims($jwt)['exp'], 'The cached expiry must come from the token, not the JWT');

        $reading = new RecordingOrigin($cache);
        $reading->initializeVariables(self::INSTALLATION_ID, base64_encode($seed), self::APP_ID);
        $reading->getAuthenticatedApp();
        $reading->getRateLimit();

        $this->assertSame(['/app', '/rate_limit'], array_column($reading->requests, 'path'), 'A cached pair must be reused without minting');
        $this->assertSame('Bearer ' . $jwt, $reading->requests[0]['authorization']);
        $this->assertSame('Bearer oit_minted', $reading->requests[1]['authorization']);
    }

    public function testGitCommandsAuthenticateWithTheTokenCurrentWhenTheyRun(): void
    {
        $adapter = new SlowGitOrigin(new Cache(new None()));
        $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)), self::APP_ID);
        $this->assertSame(1, $adapter->mints);

        $adapter->createBranch(self::OWNER, self::REPOSITORY_NAME, 'new-branch', 'main');

        [$init, $fetch, $revParse, $push] = $adapter->commands;
        $this->assertStringContainsString("'init'", $init);
        $this->assertStringContainsString("'fetch'", $fetch);
        $this->assertStringContainsString("'rev-parse'", $revParse);
        $this->assertStringContainsString("'push'", $push);

        $this->assertStringContainsString('x-access-token:oit_2@', $fetch, 'The fetch must carry the token minted after the previous command expired it');
        $this->assertStringContainsString('x-access-token:oit_3@', $push, 'The push must not reuse the token the fetch started with');
        $this->assertSame(3, $adapter->mints);
    }

    public function testFailedCommandMasksTheRefreshedToken(): void
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $expiring = $this->appJwt(time() + self::EXPIRING_IN, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));

        $adapter = $this->cachedOrigin(
            new Cache(new Memory()),
            ['jwtToken' => $expiring, 'accessToken' => 'oit_old'],
            $seed,
            [self::MINT_PATH => [201, ['token' => 'oit_secret/+']]],
        );

        $remote = $adapter->cloneUrl(self::OWNER, self::REPOSITORY_NAME);
        $this->assertStringContainsString('x-access-token:' . urlencode('oit_secret/+') . '@', $remote);

        try {
            $adapter->runCommand(new Command('ls')->argument($remote));
        } catch (Exception $error) {
            $this->assertStringStartsWith('Running the command failed: ', $error->getMessage());
            $this->assertStringContainsString('x-access-token:***@', $error->getMessage());
            $this->assertStringNotContainsString('oit_secret', $error->getMessage());

            return;
        }

        $this->fail('Expected listing a URL as a path to fail');
    }

    public function testLiveAdapterSuite(): void
    {
        $this->markTestSkipped(
            'Origin cannot run the shared live suite: the partner API does not allow app installations to create repositories, and it has no repository deletion endpoint, so fixture repositories can neither be provisioned nor cleaned up.',
        );
    }
}
