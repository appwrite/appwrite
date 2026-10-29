<?php

namespace Utopia\VCS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
    public function write(string $state, string $name, string $title, string $summary = '', string $detailsUrl = '', string $suite = '', string $key = '', string $suiteKey = ''): array
    {
        return $this->checkRuns('main', $state, $name, $title, $summary, $detailsUrl, $suite, $key, $suiteKey);
    }
}

/**
 * Entire whose repository is already resolved and whose data-plane calls are
 * recorded and answered in turn from a script, so the requests an operation
 * makes and its reading of their answers can be checked.
 */
class DataCallsEntire extends Entire
{
    /**
     * @var list<array{method: string, path: string, params: array<mixed>, version: string}>
     */
    public array $calls = [];

    /**
     * @param list<array{int, array<mixed>}> $answers The status and body each call in turn is answered with
     */
    public function __construct(private array $answers)
    {
        parent::__construct(new Cache(new None()));
    }

    #[\Override]
    protected function repositoryId(string $owner, string $repositoryName): string
    {
        return 'repo_' . $repositoryName;
    }

    #[\Override]
    protected function gitRepositoryId(string $repositoryId): string
    {
        return 'regional_' . $repositoryId;
    }

    #[\Override]
    protected function dataCall(string $repositoryId, string $method, string $path, array $params = [], bool $decode = true, string $version = 'v1'): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params, 'version' => $version];
        [$status, $body] = array_shift($this->answers) ?? [200, []];

        return ['headers' => ['status-code' => $status], 'body' => $body];
    }
}

/**
 * Entire acting for a known installation whose core calls are answered in
 * turn from a script and recorded, and which looks for a created
 * repository's placement without waiting, so creation and the
 * installation's repository list can be followed.
 */
class CoreCallsEntire extends Entire
{
    /**
     * @var list<array{method: string, path: string, params: array<mixed>}>
     */
    public array $calls = [];

    /**
     * @var list<array{endpoint: string, authorization: string}> Where each call went, and with which credential
     */
    public array $targets = [];

    /**
     * @param list<array{int, array<mixed>}> $answers The status and body each call in turn is answered with
     * @param array<string, string>|null $permissions The permissions the installation token holds
     */
    public function __construct(private array $answers, ?array $permissions = ['contents' => 'write', 'repositories' => 'create'])
    {
        parent::__construct(new Cache(new None()));
        $this->plugin = 'appwrite';
        $this->installationId = 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $this->privateKey = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->accessToken = 'token';
        $this->permissions = $permissions;
        $this->provisioningInterval = 0;
    }

    #[\Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params];
        $this->targets[] = ['endpoint' => $this->endpoint, 'authorization' => $headers['Authorization'] ?? ''];
        [$status, $body] = array_shift($this->answers) ?? [200, []];

        return ['headers' => ['status-code' => $status], 'body' => $body];
    }
}

/**
 * Entire whose token mints are recorded and answered in turn from a script of
 * error codes ('' mints), so the permission sets a mint tries can be read.
 */
class MintingEntire extends Entire
{
    protected const array STATUSES = [
        '' => 201,
        'permission_not_accepted' => 403,
        'installation_suspended' => 403,
        'unsupported_permission' => 400,
        'repository_required' => 400,
        'coverage_changed' => 409,
    ];

    /**
     * @var list<array<mixed>>
     */
    public array $requested = [];

    /**
     * @param list<string> $codes What each mint in turn is answered with
     */
    public function __construct(private array $codes)
    {
        parent::__construct(new Cache(new None()));
    }

    #[\Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $permissions = \is_array($params['permissions'] ?? null) ? $params['permissions'] : [];
        $this->requested[] = $permissions;
        $code = $this->code($permissions);

        return [
            'headers' => ['status-code' => self::STATUSES[$code] ?? 503],
            'body' => $code === ''
                ? ['token' => 'token', 'username' => 'x-access-token', 'expires_at' => '2026-10-27T10:00:00Z', 'permissions' => $permissions]
                : ['code' => $code],
        ];
    }

    /**
     * @param array<mixed> $permissions
     */
    protected function code(array $permissions): string
    {
        return array_shift($this->codes) ?? 'unavailable';
    }
}

/**
 * Entire answering a mint the way its core does for an installation holding
 * a given set: only that exact set mints.
 */
class HoldingEntire extends MintingEntire
{
    /**
     * @param array<string, string> $held
     */
    public function __construct(private readonly array $held)
    {
        parent::__construct([]);
    }

    #[\Override]
    protected function code(array $permissions): string
    {
        if ($permissions == $this->held) {
            return '';
        }

        foreach ($permissions as $resource => $access) {
            if (($this->held[$resource] ?? null) !== $access) {
                return 'permission_not_accepted';
            }
        }

        return 'repository_required';
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
        // entire.io only redirects to entire.com
        $this->assertSame('entire.com', $parsed['host'] ?? '');
        $this->assertSame('/plugins/appwrite/install', $parsed['path'] ?? '');
        $this->assertSame('https://appwrite.test/v1/vcs/entire/callback', $params['redirect_uri'] ?? '');
        $this->assertSame('{"projectId":"p1"}', $params['state'] ?? '');

        $this->assertSame('https://entire.com/plugins/appwrite/install', $this->adapter->getInstallUrl(self::PLUGIN));
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
                        'default_branch' => 'refs/heads/main',
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
        // Entire names the default branch by its full ref; consumers get its name
        $this->assertSame('main', $event['defaultBranch']);
        $this->assertSame('github:alice', $event['headCommitAuthorName']);
        $this->assertFalse($event['branchCreated']);
        $this->assertFalse($event['branchDeleted']);
        $this->assertFalse($event['forced']);
        $this->assertFalse($event['external']);
        // Deliveries carry no file lists
        $this->assertSame([], $event['affectedFiles']);
        $this->assertSame('https://entire.com/et/test-project/test-repo', $event['repositoryUrl']);
        $this->assertSame('https://entire.com/et/test-project/test-repo/commits/main', $event['branchUrl']);
        $this->assertSame('https://entire.com/et/test-project/test-repo/commit/' . self::COMMIT_HASH, $event['headCommitUrl']);
    }

    public function testGetEventFromAGithubMirror(): void
    {
        $payload = array_replace_recursive($this->refPayload('ref.updated', 'refs/heads/main'), [
            'event' => ['payload' => ['repository' => ['path' => '/gh/octocat/hello-world']]],
        ]);

        $event = $this->adapter->getEvents('ref.updated', (string) json_encode($payload))[0];

        // The mirror keeps its forge in its owner, and its links go under gh
        $this->assertSame('gh/octocat', $event['owner']);
        $this->assertSame('hello-world', $event['repositoryName']);
        $this->assertSame('https://entire.com/gh/octocat/hello-world', $event['repositoryUrl']);
        $this->assertSame('https://entire.com/gh/octocat/hello-world/commits/main', $event['branchUrl']);
        $this->assertSame('https://entire.com/gh/octocat/hello-world/commit/' . self::COMMIT_HASH, $event['headCommitUrl']);
    }

    public function testMirrorsOfSeveralGithubOwners(): void
    {
        // As Entire lists a mirror: the full name without its forge, the
        // path with it and in lower case
        $mirror = static fn (string $id, string $fullName): array => [
            'id' => $id,
            'full_name' => $fullName,
            'path' => '/gh/' . strtolower($fullName),
            'entire_clone_url' => 'entire://aws-eu-central-1.entire.io/gh/' . strtolower($fullName),
            'placements' => [['role' => 'processing_primary', 'api_base_url' => self::CELL]],
        ];
        $adapter = new CoreCallsEntire([[200, ['repositories' => [
            $mirror('repo_01M5HELLO', 'Octocat/hello-world'),
            $mirror('repo_01M5DOCS', 'appwrite/docs'),
        ], 'next_cursor' => '']]]);

        // The installation's owner is its first mirror's, spelled as GitHub does, with its forge
        $this->assertSame('gh/Octocat', $adapter->getOwnerName(self::INSTALLATION_ID));

        // Both owners' mirrors are offered under it
        $listed = $adapter->searchRepositories('gh/Octocat', 1, 10);
        $this->assertSame(2, $listed['total']);
        $this->assertSame(['gh/Octocat', 'gh/appwrite'], array_column($listed['items'], 'organization'));

        // A repository is found by its name under the installation's owner, and links under its own
        $this->assertSame('entire://aws-eu-central-1.entire.io/gh/appwrite/docs', $adapter->getRepositoryCloneUrl('gh/Octocat', 'docs'));
        $this->assertSame('https://entire.com/gh/appwrite/docs', $adapter->getRepositoryUrl('gh/Octocat', 'docs'));
        $this->assertSame('https://entire.com/gh/Octocat/hello-world/commit/abc', $adapter->getCommitUrl('gh/octocat', 'hello-world', 'abc'));

        // The repository list was read once
        $this->assertCount(1, $adapter->calls);
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

    public function testGetMergeBase(): void
    {
        $adapter = new DataCallsEntire([[200, ['mergeBaseSHA' => self::PREVIOUS_COMMIT_HASH, 'resolvedSHAs' => [self::PREVIOUS_COMMIT_HASH, self::COMMIT_HASH]]]]);

        $this->assertSame(self::PREVIOUS_COMMIT_HASH, $adapter->getMergeBase(self::OWNER, self::REPOSITORY_NAME, 'main', self::COMMIT_HASH));
        $this->assertSame([[
            'method' => 'GET',
            'path' => '/git/repos/regional_repo_test-repo/merge-base',
            'params' => ['commitA' => 'main', 'commitB' => self::COMMIT_HASH],
            'version' => 'v1',
        ]], $adapter->calls);

        $this->expectException(\Exception::class);
        new DataCallsEntire([[404, ['code' => 'not_found']]])->getMergeBase(self::OWNER, self::REPOSITORY_NAME, 'main', self::COMMIT_HASH);
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
        // An Entire-native repository, under its project
        $this->assertSame('https://entire.com/et/acme', $this->adapter->getOrganizationUrl('acme'));
        $this->assertSame('https://entire.com/et/acme/api', $this->adapter->getRepositoryUrl('acme', 'api'));
        $this->assertSame('https://entire.com/et/acme/api/commits/main', $this->adapter->getBranchUrl('acme', 'api', 'main'));
        $this->assertSame('https://entire.com/et/acme/api/commits/feature%2Fpricing', $this->adapter->getBranchUrl('acme', 'api', 'feature/pricing'));
        $this->assertSame('https://entire.com/et/acme/api/commit/abc', $this->adapter->getCommitUrl('acme', 'api', 'abc'));
        $this->assertSame('https://entire.com/et/acme/api/files/docs/guide.md?ref=main', $this->adapter->getFileUrl('acme', 'api', 'main/docs/guide.md'));
        $this->assertSame('https://entire.com/et/acme/api/trails/7', $this->adapter->getPullRequestUrl('acme', 'api', 7));

        // A GitHub mirror, whose owner names its forge
        $this->assertSame('https://entire.com/gh/octocat', $this->adapter->getOrganizationUrl('gh/octocat'));
        $this->assertSame('https://entire.com/gh/octocat/hello-world', $this->adapter->getRepositoryUrl('gh/octocat', 'hello-world'));
        $this->assertSame('https://entire.com/gh/octocat/hello-world/commits/main', $this->adapter->getBranchUrl('gh/octocat', 'hello-world', 'main'));
        $this->assertSame('https://entire.com/gh/octocat/hello-world/commit/abc', $this->adapter->getCommitUrl('gh/octocat', 'hello-world', 'abc'));
        $this->assertSame('https://entire.com/gh/octocat/hello-world/trails/7', $this->adapter->getPullRequestUrl('gh/octocat', 'hello-world', 7));
    }

    public function testCapabilities(): void
    {
        $this->assertSame('entire', $this->adapter->getName());
        $this->assertSame([Entire::WEBHOOK_SCOPE_INSTALLATION], $this->adapter->getSupportedWebhookScopes());
        $this->assertSame('authorization', $this->adapter->getSignatureHeaderName());
        $this->assertTrue($this->adapter->supportsRepositoryCreation());
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
        // Without a suite the run gets one of its own, which carries the run's state and no output
        $this->assertSame([
            'key' => 'Site (Project)',
            'name' => 'Site (Project)',
            'external_id' => 'Site (Project)',
            'external_updated_at' => $run['external_updated_at'],
            'status' => 'queued',
        ], $queued['check_suite']);

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

    public function testCheckRunsShareTheirSuite(): void
    {
        $adapter = new CheckRunsEntire(new Cache(new None()));

        $billing = $adapter->write('success', 'Billing', 'Build succeeded.', suite: 'Atlas Production');
        $hello = $adapter->write('pending', 'Hello', 'Building...', suite: 'Atlas Production');

        // Each resource is a run of its project's suite, which updateCheck() states once the run landed
        $suite = ['key' => 'Atlas Production', 'name' => 'Atlas Production', 'external_id' => 'Atlas Production'];
        $this->assertSame($suite, $billing['check_suite']);
        $this->assertSame($suite, $hello['check_suite']);
        $this->assertSame('Billing', $billing['check_runs'][0]['key']);
        $this->assertSame('completed', $billing['check_runs'][0]['status']);
        $this->assertSame('Hello', $hello['check_runs'][0]['name']);
        $this->assertSame('in_progress', $hello['check_runs'][0]['status']);
    }

    public function testUpdateDeployment(): void
    {
        $adapter = new DataCallsEntire([
            [200, [self::COMMIT_HASH => ['refs/heads/' . self::HEAD_BRANCH]]],
            [200, []],
        ]);

        $adapter->updateDeployment(self::OWNER, self::REPOSITORY_NAME, self::COMMIT_HASH, 'production/landing', 'Landing', 'success', 'production', 'https://landing.appwrite.network', 'https://cloud.appwrite.io/console/logs', 'site', 'production', 'Production');

        // The deployment names the branch holding the commit, on the cell's v0 API
        $this->assertSame('POST', $adapter->calls[1]['method']);
        $this->assertSame('/repos/repo_test-repo/commits/' . self::COMMIT_HASH . '/deployments', $adapter->calls[1]['path']);
        $this->assertSame('v0', $adapter->calls[1]['version']);
        $this->assertSame(self::HEAD_BRANCH, $this->param($adapter, 1, 'branch'));

        $this->assertSame('production/landing', $this->param($adapter, 1, 'deployments', 0, 'key'));
        $this->assertSame('Landing', $this->param($adapter, 1, 'deployments', 0, 'name'));
        $this->assertSame('completed', $this->param($adapter, 1, 'deployments', 0, 'status'));
        $this->assertSame('success', $this->param($adapter, 1, 'deployments', 0, 'conclusion'));
        $this->assertSame('production', $this->param($adapter, 1, 'deployments', 0, 'environment'));
        $this->assertSame('site', $this->param($adapter, 1, 'deployments', 0, 'type'));
        $this->assertSame(['key' => 'production', 'name' => 'Production'], $this->param($adapter, 1, 'deployments', 0, 'group'));
        $this->assertSame('https://landing.appwrite.network', $this->param($adapter, 1, 'deployments', 0, 'environment_url'));
        $this->assertSame('https://cloud.appwrite.io/console/logs', $this->param($adapter, 1, 'deployments', 0, 'logs_url'));
        $this->assertSame($this->param($adapter, 1, 'deployments', 0, 'external_updated_at'), $this->param($adapter, 1, 'deployments', 0, 'completed_at'));
    }

    public function testUpdateDeploymentStates(): void
    {
        $adapter = new DataCallsEntire([[200, [self::COMMIT_HASH => ['refs/heads/' . self::HEAD_BRANCH]]]]);

        // A running build has no conclusion, and only https links reach Entire
        $adapter->updateDeployment(self::OWNER, self::REPOSITORY_NAME, self::COMMIT_HASH, 'p/billing', 'Billing', 'pending', logsUrl: 'http://localhost/console/logs', type: 'function');
        $this->assertSame('in_progress', $this->param($adapter, 1, 'deployments', 0, 'status'));
        $this->assertNull($this->param($adapter, 1, 'deployments', 0, 'conclusion'));
        $this->assertSame('preview', $this->param($adapter, 1, 'deployments', 0, 'environment'));
        $this->assertNull($this->param($adapter, 1, 'deployments', 0, 'logs_url'));
        $this->assertNull($this->param($adapter, 1, 'deployments', 0, 'group'));

        // A gone resource offers no links, and the commit's branch is looked up once
        $adapter->updateDeployment(self::OWNER, self::REPOSITORY_NAME, self::COMMIT_HASH, 'p/landing', 'Landing', 'inactive', url: 'https://landing.appwrite.network', logsUrl: 'https://cloud.appwrite.io/console/logs');
        $this->assertSame(['POST', 'POST', 'POST'], array_column($adapter->calls, 'method'));
        $this->assertSame('inactive', $this->param($adapter, 2, 'deployments', 0, 'status'));
        $this->assertNull($this->param($adapter, 2, 'deployments', 0, 'conclusion'));
        $this->assertNull($this->param($adapter, 2, 'deployments', 0, 'environment_url'));
        $this->assertNull($this->param($adapter, 2, 'deployments', 0, 'logs_url'));
    }

    public function testCreateRepositoryWaitsForItsPlacement(): void
    {
        $listed = static fn (array $placements): array => [200, ['repositories' => [[
            'id' => 'repo_01M5NEW',
            'full_name' => 'et/' . self::OWNER . '/pricing-site',
            'clone_url' => 'https://aws-eu-central-1.entire.io/et/' . self::OWNER . '/pricing-site.git',
            'placements' => $placements,
        ]], 'next_cursor' => '']];
        $adapter = new CoreCallsEntire([
            [201, ['repositoryId' => 'repo_01M5NEW', 'name' => 'pricing-site', 'owningProjectId' => '01M4ZC', 'state' => 'provisioning']],
            $listed([]),
            $listed([['region' => 'aws-eu-central-1', 'role' => 'processing_primary', 'api_base_url' => self::CELL]]),
        ]);

        $repository = $adapter->createRepository(self::OWNER, 'pricing-site', false);

        // The core creates it in the installation's project, named alone
        $this->assertSame(['method' => 'POST', 'path' => '/repos', 'params' => ['name' => 'pricing-site']], $adapter->calls[0]);
        // The installation lists it before its placement exists, then with one
        $this->assertSame('/plugins/appwrite/installations/ins_01ARZ3NDEKTSV4RRFFQ69G5FAV/repositories', $adapter->calls[2]['path']);
        $this->assertCount(3, $adapter->calls);

        $this->assertSame('repo_01M5NEW', $repository['id']);
        $this->assertSame('pricing-site', $repository['name']);
        $this->assertSame(self::OWNER, $repository['organization']);
        $this->assertTrue($repository['private']);
    }

    public function testCreateRepositoryOnTheProjectsHomeCore(): void
    {
        // The configured core is another jurisdiction's than the project's
        $adapter = new CoreCallsEntire([
            [421, [
                'error' => 'project "' . self::OWNER . '" is homed in jurisdiction "eu"; retry against the home core',
                'home_core_url' => 'https://eu.auth.entire.io',
                'jurisdiction' => 'eu',
            ]],
            [201, ['token' => 'home', 'expires_at' => '2026-10-27T14:00:00Z', 'permissions' => ['repositories' => 'create', 'installations' => 'read']]],
            [201, ['repositoryId' => 'repo_01M5NEW', 'name' => 'pricing-site', 'owningProjectId' => '01M4ZC', 'state' => 'active']],
            [200, ['repositories' => [[
                'id' => 'repo_01M5NEW',
                'full_name' => 'et/' . self::OWNER . '/pricing-site',
                'placements' => [['region' => 'aws-eu-central-1', 'role' => 'processing_primary', 'api_base_url' => 'https://aws-eu-central-1.api.entire.io']],
            ]], 'next_cursor' => '']],
        ]);

        $repository = $adapter->createRepository(self::OWNER, 'pricing-site', true);

        // The home core mints a token for creation alone, and creates with it
        $this->assertSame('https://us.auth.entire.io/api/v1', $adapter->targets[0]['endpoint']);
        $this->assertSame(['method' => 'POST', 'path' => '/plugins/appwrite/installations/ins_01ARZ3NDEKTSV4RRFFQ69G5FAV/access-tokens', 'params' => ['permissions' => ['repositories' => 'create']]], $adapter->calls[1]);
        $this->assertSame('https://eu.auth.entire.io/api/v1', $adapter->targets[1]['endpoint']);
        $this->assertSame(['method' => 'POST', 'path' => '/repos', 'params' => ['name' => 'pricing-site']], $adapter->calls[2]);
        $this->assertSame(['endpoint' => 'https://eu.auth.entire.io/api/v1', 'authorization' => 'Bearer home'], $adapter->targets[2]);
        // Any core lists the installation, so the configured one waits for the placement
        $this->assertSame('https://us.auth.entire.io/api/v1', $adapter->targets[3]['endpoint']);
        $this->assertSame('repo_01M5NEW', $repository['id']);
    }

    public function testCreateRepositoryFollowsOnlyAKnownCore(): void
    {
        $adapter = new CoreCallsEntire([[421, ['error' => 'retry against the home core', 'home_core_url' => 'https://core.example.com']]]);

        try {
            $adapter->createRepository(self::OWNER, 'pricing-site', true);
            $this->fail('A redirect to an unknown core must not be followed');
        } catch (\Exception $e) {
            $this->assertSame(421, $e->getCode());
            $this->assertStringContainsString('retry against the home core', $e->getMessage());
        }

        $this->assertCount(1, $adapter->calls);
    }

    public function testCreateRepositoryNamesAnExistingRepository(): void
    {
        $adapter = new CoreCallsEntire([[409, ['type' => 'https://entire.com/errors/name-in-use', 'title' => 'Conflict']]]);

        try {
            $adapter->createRepository(self::OWNER, 'pricing-site', true);
            $this->fail('A taken name must fail');
        } catch (\Exception $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertSame('A repository named pricing-site already exists in ' . self::OWNER . '.', $e->getMessage());
        }
    }

    public function testCreateRepositoryNeedsTheRepositoriesPermission(): void
    {
        // A cached token without repository creation, and an installation that still has not accepted it
        $checks = ['contents' => 'read', 'checks' => 'write'];
        $adapter = new CoreCallsEntire([[201, ['token' => 'fresh', 'expires_at' => '2026-10-27T10:00:00Z', 'permissions' => $checks]]], $checks);

        try {
            $adapter->createRepository(self::OWNER, 'pricing-site', true);
            $this->fail('Creation without the permission must fail');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('Connect an existing repository', $e->getMessage());
        }

        // One fresh mint, and no creation
        $this->assertCount(1, $adapter->calls);
        $this->assertStringEndsWith('/access-tokens', $adapter->calls[0]['path']);
    }

    public function testCreateRepositoryRefreshesATokenCachedBeforeThePermission(): void
    {
        // The installation accepted repository creation after its token was cached
        $adapter = new CoreCallsEntire([
            [201, ['token' => 'fresh', 'expires_at' => '2026-10-27T10:00:00Z', 'permissions' => Entire::PERMISSIONS]],
            [201, ['repositoryId' => 'repo_01M5NEW', 'name' => 'pricing-site', 'owningProjectId' => '01M4ZC', 'state' => 'provisioning']],
            [200, ['repositories' => [[
                'id' => 'repo_01M5NEW',
                'full_name' => 'et/' . self::OWNER . '/pricing-site',
                'placements' => [['region' => 'aws-eu-central-1', 'role' => 'processing_primary', 'api_base_url' => self::CELL]],
            ]], 'next_cursor' => '']],
        ], ['contents' => 'read', 'checks' => 'write']);

        $repository = $adapter->createRepository(self::OWNER, 'pricing-site', true);

        $this->assertStringEndsWith('/access-tokens', $adapter->calls[0]['path']);
        $this->assertSame(['method' => 'POST', 'path' => '/repos', 'params' => ['name' => 'pricing-site']], $adapter->calls[1]);
        $this->assertSame('repo_01M5NEW', $repository['id']);
    }

    public function testUpdateDeploymentNeedsTheDeploymentsPermission(): void
    {
        // An installation that has not accepted deployments gets a token without them
        $refused = 'permission_not_accepted';
        $adapter = new MintingEntire([$refused, $refused, $refused, $refused, '']);
        $adapter->setIssuer(self::ISSUER);
        $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)), self::PLUGIN);

        $adapter->updateDeployment(self::OWNER, self::REPOSITORY_NAME, self::COMMIT_HASH, 'p/landing', 'Landing', 'success');

        // Five mints and no report
        $this->assertCount(5, $adapter->requested);
    }

    public function testCheckRunsAreKeyedApartFromTheirNames(): void
    {
        $adapter = new CheckRunsEntire(new Cache(new None()));

        // Two projects named alike, each with a site named alike
        $production = $adapter->write('success', 'Landing', 'Build succeeded.', suite: 'Atlas', key: 'production/sites/landing', suiteKey: 'production');
        $staging = $adapter->write('failure', 'Landing', 'Build failed.', suite: 'Atlas', key: 'staging/sites/landing', suiteKey: 'staging');

        $this->assertSame(['key' => 'production', 'name' => 'Atlas', 'external_id' => 'production'], $production['check_suite']);
        $this->assertSame('staging', $staging['check_suite']['key']);
        $this->assertSame('production/sites/landing', $production['check_runs'][0]['key']);
        $this->assertSame('staging/sites/landing', $staging['check_runs'][0]['external_id']);
        $this->assertSame('Landing', $staging['check_runs'][0]['name']);
    }

    public function testSharedSuiteIsStatedFromEveryRun(): void
    {
        $runs = [
            ['suite_key' => 'Atlas Production', 'key' => 'Billing', 'status' => 'in_progress', 'conclusion' => null],
            ['suite_key' => 'Atlas Production', 'key' => 'Hello', 'status' => 'completed', 'conclusion' => 'failure'],
            ['suite_key' => 'Atlas Staging', 'key' => 'Billing (staging)', 'status' => 'in_progress', 'conclusion' => null],
        ];
        $adapter = new DataCallsEntire([
            [200, [self::COMMIT_HASH => ['refs/heads/' . self::HEAD_BRANCH]]],
            [200, []],
            [200, ['check_runs' => $runs]],
            [200, []],
        ]);

        $adapter->updateCheck(self::OWNER, self::REPOSITORY_NAME, self::COMMIT_HASH, 'success', 'Billing', 'Build succeeded.', suite: 'Atlas Production');

        // The run lands, the commit's runs are read, and the suite is written alone
        $path = '/repos/repo_test-repo/commits/' . self::COMMIT_HASH . '/check-runs';
        $this->assertSame(['POST', 'POST', 'GET', 'POST'], array_column($adapter->calls, 'method'));
        $this->assertSame([$path, $path, $path], array_column(\array_slice($adapter->calls, 1), 'path'));
        $this->assertNull($this->param($adapter, 1, 'check_suite', 'status'));
        $this->assertSame('completed', $this->param($adapter, 1, 'check_runs', 0, 'status'));

        // Billing now succeeded, but Hello failed, so the suite did; the
        // staging run is another suite's
        $this->assertSame(self::HEAD_BRANCH, $this->param($adapter, 3, 'branch'));
        $this->assertSame([], $this->param($adapter, 3, 'check_runs'));
        $this->assertSame('Atlas Production', $this->param($adapter, 3, 'check_suite', 'key'));
        $this->assertSame('completed', $this->param($adapter, 3, 'check_suite', 'status'));
        $this->assertSame('failure', $this->param($adapter, 3, 'check_suite', 'conclusion'));
        $this->assertSame($this->param($adapter, 3, 'check_suite', 'external_updated_at'), $this->param($adapter, 3, 'check_suite', 'completed_at'));

        // A run still building keeps the suite running
        $adapter = new DataCallsEntire([
            [200, [self::COMMIT_HASH => ['refs/heads/' . self::HEAD_BRANCH]]],
            [200, []],
            [200, ['check_runs' => $runs]],
            [200, []],
        ]);
        $adapter->updateCheck(self::OWNER, self::REPOSITORY_NAME, self::COMMIT_HASH, 'success', 'Hello', 'Build succeeded.', suite: 'Atlas Production');
        $this->assertSame('in_progress', $this->param($adapter, 3, 'check_suite', 'status'));
        $this->assertNull($this->param($adapter, 3, 'check_suite', 'conclusion'));
    }

    /**
     * The value at a path in a recorded call's parameters, or null where
     * the path leads nowhere.
     */
    protected function param(DataCallsEntire $adapter, int $call, string|int ...$path): mixed
    {
        $value = $adapter->calls[$call]['params'];
        foreach ($path as $key) {
            $value = \is_array($value) ? $value[$key] ?? null : null;
        }

        return $value;
    }

    public function testCheckRunsOutputAndDetails(): void
    {
        $adapter = new CheckRunsEntire(new Cache(new None()));

        $summary = "- Site best in project QA\n- Preview: https://x.example.com";
        $body = $adapter->write('success', 'check', 'Build succeeded.', $summary, 'https://x.example.com');
        $run = $body['check_runs'][0];
        $this->assertSame(['title' => 'Build succeeded.', 'summary' => $summary], $run['output']);
        $this->assertSame('https://x.example.com', $run['details_url']);
        // A suite covers several resources, so only the run links to one
        $this->assertArrayNotHasKey('details_url', $body['check_suite']);

        // Only an https URL without credentials reaches Entire
        $this->assertArrayNotHasKey('details_url', $adapter->write('success', 'check', 'Done.', '', 'http://localhost/console')['check_runs'][0]);
        $this->assertArrayNotHasKey('details_url', $adapter->write('success', 'check', 'Done.', '', 'https://user:secret@example.com')['check_runs'][0]);

        // No name falls to the default key; no title means no output
        $plain = $adapter->write('pending', '', '');
        $this->assertSame('default', $plain['check_runs'][0]['key']);
        $this->assertArrayNotHasKey('output', $plain['check_runs'][0]);
    }

    /**
     * @return \Iterator<string, array{list<string>, list<array<string, string>>}>
     */
    public static function mints(): \Iterator
    {
        $withoutDiscussions = ['contents' => 'write', 'checks' => 'write', 'deployments' => 'write', 'repositories' => 'create'];
        $beforeCreation = ['contents' => 'read', 'checks' => 'write', 'deployments' => 'write', 'discussions' => 'write'];
        $deployments = ['contents' => 'read', 'checks' => 'write', 'deployments' => 'write'];
        $discussions = ['contents' => 'read', 'checks' => 'write', 'discussions' => 'write'];
        $checks = ['contents' => 'read', 'checks' => 'write'];
        $contents = ['contents' => 'read'];
        $refused = 'permission_not_accepted';
        yield 'every permission accepted' => [[''], [Entire::PERMISSIONS]];
        yield 'discussions no longer declared' => [['unsupported_permission', ''], [Entire::PERMISSIONS, $withoutDiscussions]];
        yield 'discussions no longer held' => [['repository_required', ''], [Entire::PERMISSIONS, $withoutDiscussions]];
        yield 'repository creation not yet accepted' => [[$refused, $refused, ''], [Entire::PERMISSIONS, $withoutDiscussions, $beforeCreation]];
        yield 'not yet accepted, as coverage changed' => [['coverage_changed', 'coverage_changed', ''], [Entire::PERMISSIONS, $withoutDiscussions, $beforeCreation]];
        yield 'deployments not yet accepted' => [[$refused, $refused, $refused, $refused, ''], [Entire::PERMISSIONS, $withoutDiscussions, $beforeCreation, $deployments, $discussions]];
        yield 'checks alone accepted' => [[$refused, $refused, $refused, $refused, $refused, ''], [Entire::PERMISSIONS, $withoutDiscussions, $beforeCreation, $deployments, $discussions, $checks]];
        yield 'contents alone accepted' => [[$refused, $refused, $refused, $refused, $refused, $refused, ''], [Entire::PERMISSIONS, $withoutDiscussions, $beforeCreation, $deployments, $discussions, $checks, $contents]];
    }

    /**
     * @param list<string> $codes
     * @param list<array<string, string>> $requested
     */
    #[DataProvider('mints')]
    public function testAccessTokenFallsBackToAcceptedPermissions(array $codes, array $requested): void
    {
        $adapter = new MintingEntire($codes);
        $adapter->setIssuer(self::ISSUER);

        $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)), self::PLUGIN);

        $this->assertSame($requested, $adapter->requested);
    }

    /**
     * @return \Iterator<string, array{array<string, string>, int}>
     */
    public static function installations(): \Iterator
    {
        yield 'installed today' => [['contents' => 'write', 'checks' => 'write', 'deployments' => 'write', 'discussions' => 'write', 'repositories' => 'create'], 1];
        yield 'installed before repository creation' => [['contents' => 'read', 'checks' => 'write', 'deployments' => 'write', 'discussions' => 'write'], 3];
        yield 'installed before discussions' => [['contents' => 'read', 'checks' => 'write'], 6];
    }

    /**
     * @param array<string, string> $held
     */
    #[DataProvider('installations')]
    public function testAccessTokenHasEveryPermissionTheInstallationHolds(array $held, int $mints): void
    {
        // A new installation holds every permission the plugin declares, and
        // Entire refuses a token for less as repository_required
        $adapter = new HoldingEntire($held);
        $adapter->setIssuer(self::ISSUER);

        $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)), self::PLUGIN);

        $this->assertCount($mints, $adapter->requested);
        $this->assertSame($held, end($adapter->requested));
    }

    public function testAccessTokenRefusalIsNotRetried(): void
    {
        // A suspended installation is refused whatever it asks for
        $adapter = new MintingEntire(['installation_suspended']);
        $adapter->setIssuer(self::ISSUER);

        try {
            $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)), self::PLUGIN);
            $this->fail('A refused mint must fail');
        } catch (\Exception $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('installation_suspended', $e->getMessage());
        }

        $this->assertSame([Entire::PERMISSIONS], $adapter->requested);
    }

    public function testAccessTokenFailsOnceEverySetIsRefused(): void
    {
        $adapter = new MintingEntire(array_fill(0, 7, 'repository_required'));
        $adapter->setIssuer(self::ISSUER);

        try {
            $adapter->initializeVariables(self::INSTALLATION_ID, base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES)), self::PLUGIN);
            $this->fail('A mint refused every set must fail');
        } catch (\Exception $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('repository_required', $e->getMessage());
        }

        $this->assertCount(7, $adapter->requested);
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

    public function testLiveAdapterSuite(): void
    {
        $this->markTestSkipped(
            'Entire cannot run the shared live suite: a plugin installation token cannot create or delete repositories, so fixture repositories can neither be provisioned nor cleaned up.',
        );
    }
}
