<?php

namespace Utopia\VCS\Adapter\Git;

use Exception;
use Utopia\Cache\Cache;
use Utopia\Console\Command;
use Utopia\Console\Console;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

/**
 * Adapter for Entire (entire.io), the agent-native Git host.
 *
 * Entire plugins authenticate like GitHub Apps: an Ed25519-signed (EdDSA)
 * plugin JWT is exchanged on a control plane ("core") for an installation
 * access token that lasts about 45 minutes. Repository data lives on regional
 * data-plane cells, so every repository call is routed to the cell serving
 * that repository, which knows the repository by its own id. Pull requests
 * are "trails"; a trail's discussions are where comments go. Git traffic goes
 * through Entire's own remote helper, git-remote-entire, with the access
 * token in ENTIRE_TOKEN - plain HTTPS clones are not served.
 *
 * @see Entire's partner guide "Building an Entire Plugin"
 * @see https://us.auth.entire.io/api/v1/openapi.json control plane
 * @see https://aws-us-east-2.api.entire.io/api/v1/openapi.json data plane
 */
class Entire extends Git
{
    public const EVENT_REF_CREATED = 'ref.created';

    public const EVENT_REF_UPDATED = 'ref.updated';

    public const EVENT_REF_DELETED = 'ref.deleted';

    public const EVENT_INSTALLATION = 'installation';

    public const CONTENTS_DIRECTORY = 'dir';

    public const CONTENTS_FILE = 'file';

    /**
     * Plugin JWT lifetime in seconds: at most five minutes after iat.
     */
    public const PLUGIN_JWT_EXPIRY = 60 * 5;

    /**
     * The core allows no clock skew on iat, so a plugin JWT is issued this
     * many seconds in the past.
     */
    public const PLUGIN_JWT_SKEW = 30;

    /**
     * How long a minted installation token is reused. Tokens last about 45
     * minutes and are dropped five minutes before their own expiry.
     */
    public const TOKEN_CACHE_TTL = 60 * 40;

    /**
     * The repository permissions the plugin registers, requested as a whole
     * so one token covers every selected repository.
     */
    public const PERMISSIONS = ['contents' => 'read', 'checks' => 'write'];

    /**
     * Check run status and conclusion for each state updateCheck() takes.
     * Entire files a cancelled run as neutral.
     */
    private const array CHECK_STATES = [
        'queued' => ['queued', null],
        'pending' => ['in_progress', null],
        'in_progress' => ['in_progress', null],
        'success' => ['completed', 'success'],
        'failure' => ['completed', 'failure'],
        'error' => ['completed', 'failure'],
        'cancelled' => ['completed', 'cancelled'],
    ];

    /**
     * Entire's cores. A receipt is accepted from these only, and any of them
     * serves any installation.
     */
    public const CORES = [
        'https://us.auth.entire.io',
        'https://eu.auth.entire.io',
        'https://au.auth.entire.io',
        'https://in.auth.entire.io',
        'https://uk.auth.entire.io',
        'https://sg.auth.entire.io',
    ];

    /**
     * Clock skew tolerated when checking token times.
     */
    protected const LEEWAY = 60;

    /**
     * Joins the trail number and the discussion id into one comment id: the
     * discussion endpoints are addressed by trail number, which the comment
     * contract does not carry.
     */
    protected const COMMENT_SEPARATOR = '-';

    /**
     * Control-plane API. Entire runs one core per jurisdiction and any serves
     * any installation; the nearest one is the right choice.
     */
    protected string $endpoint = 'https://us.auth.entire.io/api/v1';

    /**
     * Control-plane origin, which the plugin JWT names in its audience.
     */
    protected string $coreEndpoint = 'https://us.auth.entire.io';

    /**
     * The issuer registered on the plugin: the iss claim of its JWTs and the
     * audience of the receipts Entire signs for it.
     */
    protected string $issuer = '';

    /**
     * Browser-facing host.
     */
    protected string $webEndpoint = 'https://entire.io';

    /**
     * Plugin slug: the {plugin} segment of the plugin API paths and the
     * audience of the webhook tokens Entire signs for it.
     */
    protected string $plugin = '';

    protected string $installationId = '';

    protected string $jwtToken = '';

    protected string $accessToken = '';

    /**
     * Published Ed25519 JWKS entries, memoized per issuer origin.
     *
     * @var array<string, array<array<mixed>>>
     */
    protected array $jwks = [];

    /**
     * Repositories the installation covers, memoized per instance.
     *
     * @var array<array<string, mixed>>|null
     */
    protected ?array $repositories = null;

    /**
     * Data-plane origin per repository id.
     *
     * @var array<string, string>
     */
    protected array $dataEndpoints = [];

    /**
     * The serving cell's own record of a repository, per control-plane id:
     * the id it addresses git endpoints by, and the default branch.
     *
     * @var array<string, array<mixed>>
     */
    protected array $regionalRepositories = [];

    /**
     * Global Headers
     *
     * @var array<string, string>
     */
    protected $headers = ['content-type' => 'application/json'];

    public function __construct(protected Cache $cache)
    {
    }

    /**
     * Point the adapter at another control plane (another jurisdiction, or a
     * local development core).
     */
    public function setEndpoint(string $endpoint): void
    {
        $this->coreEndpoint = rtrim($endpoint, '/');
        $this->endpoint = $this->coreEndpoint . '/api/v1';
    }

    /**
     * The issuer registered on the plugin, see $issuer.
     */
    public function setIssuer(string $issuer): void
    {
        $this->issuer = $issuer;
    }

    /**
     * Get Adapter Name
     */
    public function getName(): string
    {
        return 'entire';
    }

    /**
     * Entire initialisation with access token generation.
     *
     * @param string $installationId Entire plugin installation id (ins_...)
     * @param string $privateKey Ed25519 private key registered on the plugin: PKCS#8 PEM as
     *                           generated by `openssl genpkey -algorithm ED25519`, or base64
     * @param string|null $appId Plugin slug, used as JWT issuer and key id
     */
    public function initializeVariables(string $installationId, string $privateKey, ?string $appId = null, ?string $accessToken = null, ?string $refreshToken = null): void
    {
        if (\in_array($appId, [null, '', '0'], true)) {
            throw new Exception('Entire requires the plugin slug to sign the plugin JWT.');
        }

        $this->installationId = $installationId;
        $this->plugin = $appId;

        // A plugin JWT lives five minutes and costs one signature, so every
        // instance signs its own; only the minted access token is worth
        // keeping across requests.
        $this->jwtToken = $this->generatePluginJwt($privateKey, $appId);

        $response = $this->cache->load('entire-' . $installationId, self::TOKEN_CACHE_TTL);
        $parsed = \is_string($response) ? json_decode($response, true) : null;
        $parsed = \is_array($parsed) ? $parsed : [];

        // The token is dropped five minutes before its own expiry
        $expiresAt = $this->number($parsed, 'expiresAt');
        $accessToken = $this->text($parsed, 'accessToken');
        if ($accessToken === '' || ($expiresAt > 0 && $expiresAt - 5 * self::LEEWAY <= time())) {
            $this->generateAccessToken($privateKey, $appId);
            return;
        }

        $this->accessToken = $accessToken;
    }

    /**
     * Generate Access Token
     *
     * Signs an EdDSA plugin JWT and exchanges it for an installation access
     * token covering every selected repository with every registered
     * permission. A plugin registered with fewer permissions than
     * PERMISSIONS is refused the extra ones, so the mint falls back to
     * reading contents alone.
     */
    protected function generateAccessToken(string $privateKey, ?string $appId): void
    {
        if (\in_array($appId, [null, '', '0'], true)) {
            throw new Exception('Entire requires the plugin slug to sign the plugin JWT.');
        }

        $this->jwtToken = $this->generatePluginJwt($privateKey, $appId);

        $path = '/plugins/' . rawurlencode($appId) . '/installations/' . rawurlencode($this->installationId) . '/access-tokens';
        $response = $this->call(self::METHOD_POST, $path, ['Authorization' => 'Bearer ' . $this->jwtToken], ['permissions' => self::PERMISSIONS]);

        if ($this->status($response) === 400 && $this->text($this->body($response), 'code') === 'unsupported_permission') {
            $response = $this->call(self::METHOD_POST, $path, ['Authorization' => 'Bearer ' . $this->jwtToken], ['permissions' => ['contents' => 'read']]);
        }

        $responseBody = $this->body($response);
        $statusCode = $this->status($response);
        if (!\array_key_exists('token', $responseBody)) {
            $safeBody = json_encode(array_intersect_key($responseBody, array_flip(['code', 'title', 'detail'])));
            throw new Exception('Failed to retrieve access token from Entire API. Status: ' . $statusCode . '. Response: ' . $safeBody, $statusCode);
        }

        $this->accessToken = $this->text($responseBody, 'token');

        $expiresAt = strtotime($this->text($responseBody, 'expires_at'));

        $this->cache->save('entire-' . $this->installationId, json_encode([
            'accessToken' => $this->accessToken,
            'expiresAt' => $expiresAt === false ? 0 : $expiresAt,
        ]) ?: '{}');
    }

    /**
     * Builds the EdDSA plugin JWT the core takes: the registered issuer, the
     * core's STS endpoint as audience, and the plugin and installation it
     * acts for. The key is registered as a PEM, so no kid names it.
     */
    protected function generatePluginJwt(string $privateKey, string $plugin): string
    {
        $secretKey = $this->ed25519SecretKey($privateKey);

        $iat = time() - self::PLUGIN_JWT_SKEW;
        $header = ['alg' => 'EdDSA', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->coreEndpoint . '/api/authz/sts',
            'plugin_id' => $plugin,
            'installation_id' => $this->installationId,
            'iat' => $iat,
            'exp' => $iat + self::PLUGIN_JWT_EXPIRY,
        ];

        $signingInput = $this->base64UrlEncode(json_encode($header) ?: '')
            . '.'
            . $this->base64UrlEncode(json_encode($claims) ?: '');

        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Resolves the configured private key to a libsodium Ed25519 secret key.
     *
     * Accepts the PKCS#8 PEM `openssl genpkey -algorithm ed25519` writes, its
     * bare base64 body (one line, for an environment variable), a raw
     * base64-encoded 32-byte seed, or a base64-encoded 64-byte libsodium
     * secret key.
     *
     * @return non-empty-string
     */
    protected function ed25519SecretKey(string $privateKey): string
    {
        $privateKey = trim($privateKey);

        $decoded = base64_decode(str_contains($privateKey, '-----BEGIN') ? $this->pemBody($privateKey) : strtr($privateKey, '-_', '+/'), true);
        if ($decoded === false) {
            throw new Exception('Failed to decode the Entire private key.');
        }

        if (\strlen($decoded) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return $decoded;
        }

        if (\strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $seed = $decoded;
        } else {
            // In PKCS#8 the Ed25519 seed is the 32 bytes after the
            // OCTET STRING wrapper (04 22 04 20).
            $marker = "\x04\x22\x04\x20";
            $offset = strpos($decoded, $marker);
            $seed = $offset !== false ? substr($decoded, $offset + 4, 32) : '';
            if (\strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
                throw new Exception('The Entire private key is not an Ed25519 PKCS#8 key.');
            }
        }

        return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
    }

    /**
     * The base64 body of a PEM block, without its armor lines and whitespace.
     */
    protected function pemBody(string $pem): string
    {
        $body = '';
        foreach (explode("\n", $pem) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '-----')) {
                continue;
            }
            $body .= $line;
        }

        return str_replace([' ', "\t", "\r"], '', $body);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $data): string
    {
        $remainder = \strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * Resolves a verification key to raw Ed25519 public key bytes: SPKI PEM,
     * or the base64/base64url raw 32 bytes (the JWK 'x' value).
     *
     * @return non-empty-string|null
     */
    protected function ed25519PublicKey(string $signatureKey): ?string
    {
        $signatureKey = trim($signatureKey);

        if (str_contains($signatureKey, '-----BEGIN')) {
            $der = base64_decode($this->pemBody($signatureKey), true);
            if ($der === false) {
                return null;
            }

            // In SPKI the Ed25519 key is the 32 bytes after the BIT STRING wrapper (03 21 00)
            $marker = "\x03\x21\x00";
            $offset = strpos($der, $marker);
            $publicKey = $offset !== false ? substr($der, $offset + 3, 32) : '';

            return \strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $publicKey : null;
        }

        $decoded = base64_decode(strtr($signatureKey, '-_', '+/'), true);
        if ($decoded !== false && \strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $decoded;
        }

        return null;
    }

    /**
     * Splits a JWT into its decoded header and claims, the raw signature and
     * the signing input. Nothing is verified here.
     *
     * @return array{header: array<mixed>, claims: array<mixed>, signature: string, signingInput: string}
     * @throws Exception
     */
    protected function decodeJwt(string $token): array
    {
        $segments = explode('.', trim($token));

        if (\count($segments) !== 3) {
            throw new Exception('Token is not a JWT');
        }

        [$headerSegment, $claimsSegment, $signatureSegment] = $segments;

        $header = json_decode($this->base64UrlDecode($headerSegment), true);
        $claims = json_decode($this->base64UrlDecode($claimsSegment), true);

        if (!\is_array($header) || !\is_array($claims)) {
            throw new Exception('Token is malformed');
        }

        return [
            'header' => $header,
            'claims' => $claims,
            'signature' => $this->base64UrlDecode($signatureSegment),
            'signingInput' => $headerSegment . '.' . $claimsSegment,
        ];
    }

    /**
     * URL of the plugin's installation page on Entire. A consumer sends a
     * user here to pick a project and approve the installation; Entire
     * confirms it by redirecting to $redirectUri with an installation receipt
     * (see verifyReceipt()) - there is no authorization-code exchange.
     *
     * @param string $redirectUri Must exactly match a post-install URL registered on the plugin
     * @param string $state Opaque value echoed back as the receipt's state claim
     */
    public function getInstallUrl(string $plugin, string $redirectUri = '', string $state = ''): string
    {
        $params = [];

        if ($redirectUri !== '' && $redirectUri !== '0') {
            $params['redirect_uri'] = $redirectUri;
        }

        if ($state !== '' && $state !== '0') {
            $params['state'] = $state;
        }

        $url = $this->webEndpoint . '/plugins/' . rawurlencode($plugin) . '/install';

        return $params === [] ? $url : $url . '?' . http_build_query($params);
    }

    /**
     * Verifies an installation receipt JWT against the signing keys of the
     * core that issued it and returns its claims. The receipt is Entire's
     * only proof of an installation, so a consumer must verify it before
     * trusting anything else on its callback. The `installation_id` claim is
     * the installation, `project` and `project_id` name the installing
     * project, `state` echoes the state given to the install URL, and `jti`
     * makes the receipt single-use - the caller keeps the ones it accepted.
     *
     * @return array<mixed>
     * @throws Exception
     */
    public function verifyReceipt(string $receipt, string $plugin): array
    {
        try {
            ['header' => $header, 'claims' => $claims, 'signature' => $signature, 'signingInput' => $signingInput] = $this->decodeJwt($receipt);
        } catch (Exception $e) {
            throw new Exception('Installation receipt ' . lcfirst($e->getMessage()), $e->getCode(), $e);
        }

        if (($header['alg'] ?? '') !== 'EdDSA') {
            throw new Exception('Installation receipt has an unexpected algorithm');
        }

        if (($header['typ'] ?? '') !== 'entire-install-receipt+jwt') {
            throw new Exception('Installation receipt has an unexpected type');
        }

        // The issuer decides where the keys come from, so it is checked
        // before any are fetched
        $issuer = rtrim($this->text($claims, 'iss'), '/');
        if (!$this->isTrustedCore($issuer)) {
            throw new Exception('Installation receipt issuer is not an Entire core');
        }

        $key = $this->signingKey($issuer, $this->text($header, 'kid'));

        if (
            \strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $signingInput, $key)
        ) {
            throw new Exception('Installation receipt signature is invalid');
        }

        $audience = \is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? ''];
        if ($this->issuer === '' || !\in_array($this->issuer, $audience, true)) {
            throw new Exception('Installation receipt audience does not include the registered audience');
        }

        if (($claims['plugin_id'] ?? '') !== $plugin) {
            throw new Exception('Installation receipt does not name this plugin');
        }

        $now = time();

        if (isset($claims['exp']) && $now >= $this->number($claims, 'exp') + self::LEEWAY) {
            throw new Exception('Installation receipt has expired');
        }

        if (isset($claims['nbf']) && $now < $this->number($claims, 'nbf') - self::LEEWAY) {
            throw new Exception('Installation receipt is not yet valid');
        }

        if (isset($claims['iat']) && $now < $this->number($claims, 'iat') - self::LEEWAY) {
            throw new Exception('Installation receipt is issued in the future');
        }

        if (empty($claims['installation_id'])) {
            throw new Exception('Installation receipt is missing the installation ID');
        }

        return $claims;
    }

    /**
     * Whether an origin is one of Entire's cores, or the configured one.
     */
    public function isTrustedCore(string $origin): bool
    {
        $origin = rtrim($origin, '/');

        return $origin !== '' && ($origin === $this->coreEndpoint || \in_array($origin, self::CORES, true));
    }

    /**
     * Active Ed25519 signing keys of an Entire origin as base64url raw key
     * material - the shape validateWebhookEvent() accepts. The core signs
     * receipts; each data-plane cell signs the deliveries it sends, so a
     * delivery is checked against the keys of its own issuer. Pass $refresh
     * when a signature fails against a cached set: keys rotate rarely, but a
     * delivery signed by a just-rotated key deserves one refetch.
     *
     * @param string $issuer Origin publishing the keys; the core when empty
     * @return array<string>
     */
    public function getSigningKeys(string $issuer = '', bool $refresh = false): array
    {
        $issuer = $issuer === '' ? $this->coreEndpoint : rtrim($issuer, '/');

        return array_values(array_map(fn (array $key): string => $this->text($key, 'x'), $this->jwks($issuer, $refresh)));
    }

    /**
     * Published Ed25519 JWKS entries of an origin, from its
     * /.well-known/jwks.json.
     *
     * @return array<array<mixed>>
     */
    protected function jwks(string $issuer, bool $refresh = false): array
    {
        if (!$refresh && isset($this->jwks[$issuer])) {
            return $this->jwks[$issuer];
        }

        $response = $this->callAt($issuer, self::METHOD_GET, '/.well-known/jwks.json');

        $keys = [];
        $body = $this->body($response);
        foreach ($this->nested($body, 'keys') as $key) {
            if (\is_array($key) && ($key['kty'] ?? '') === 'OKP' && ($key['crv'] ?? '') === 'Ed25519' && !empty($key['x'])) {
                $keys[] = $key;
            }
        }

        return $this->jwks[$issuer] = $keys;
    }

    /**
     * Raw Ed25519 public key bytes for a JWKS key id of an origin.
     *
     * @return non-empty-string
     * @throws Exception
     */
    protected function signingKey(string $issuer, string $kid): string
    {
        foreach ($this->jwks($issuer) as $key) {
            if ($this->text($key, 'kid') !== $kid) {
                continue;
            }

            $publicKey = $this->ed25519PublicKey($this->text($key, 'x'));
            if ($publicKey !== null) {
                return $publicKey;
            }
        }

        throw new Exception('Token is signed with an unknown key');
    }

    /**
     * Unverified claims of a delivery token, plus the header's key id as
     * `kid`, so a consumer can pick the issuer whose keys verify it: `iss` is
     * the origin of the cell that signed the delivery, `aud` the plugin slug
     * it was sent to. Nothing here is trusted until validateWebhookEvent()
     * accepts the token.
     *
     * @param string $authorization The Authorization header, "Bearer <token>", or the bare token
     * @return array<mixed>
     * @throws Exception
     */
    public function getWebhookClaims(string $authorization): array
    {
        ['header' => $header, 'claims' => $claims] = $this->decodeJwt($this->bearerToken($authorization));

        $claims['kid'] = $this->text($header, 'kid');

        return $claims;
    }

    /**
     * Whether a delivery issuer belongs to the configured Entire deployment:
     * the core's own domain or a cell beneath it, over HTTPS. A token from
     * anywhere else must not have its keys fetched, let alone trusted.
     */
    public function isTrustedIssuer(string $issuer): bool
    {
        $issuer = rtrim($issuer, '/');
        $parts = parse_url($issuer);
        if ($parts === false) {
            return false;
        }

        $host = strtolower($this->text($parts, 'host'));
        $scheme = strtolower($this->text($parts, 'scheme'));
        if ($host === '' || !empty($parts['path']) || !empty($parts['query'])) {
            return false;
        }

        $core = parse_url($this->coreEndpoint);
        $coreScheme = \is_array($core) ? strtolower($this->text($core, 'scheme')) : '';
        if ($scheme !== 'https' && $scheme !== $coreScheme) {
            return false;
        }

        $domain = $this->trustedDomain();

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    /**
     * The registrable domain of the core: entire.io for us.console.entire.io.
     * A single-label host (a local development core) is its own domain.
     */
    protected function trustedDomain(): string
    {
        $core = parse_url($this->coreEndpoint);
        $host = \is_array($core) ? strtolower($this->text($core, 'host')) : '';
        $labels = explode('.', $host);

        return implode('.', \array_slice($labels, -2));
    }

    protected function bearerToken(string $authorization): string
    {
        $authorization = trim($authorization);

        if (strncasecmp($authorization, 'Bearer ', 7) === 0) {
            return trim(substr($authorization, 7));
        }

        return $authorization;
    }

    /**
     * Validates a webhook delivery token.
     *
     * Entire signs each delivery with an EdDSA JWT carried as a bearer token
     * rather than an HMAC over the body: the token binds to the body through
     * its `body_sha256` claim, the unpadded base64url SHA-256 of the exact
     * request bytes, and lives five minutes. The signature, the lifetime and
     * the body digest are checked here; the issuer and the audience come
     * from getWebhookClaims(), so the caller decides whose keys to try.
     *
     * @param string $payload The raw request body
     * @param string $signature The Authorization header, "Bearer <token>", or the bare token
     * @param string $signatureKey The issuer's Ed25519 public key: SPKI PEM, or the
     *                             base64/base64url raw 32 bytes (the JWK 'x' value)
     */
    #[\Override]
    public function validateWebhookEvent(string $payload, string $signature, string $signatureKey): bool
    {
        $publicKey = $this->ed25519PublicKey($signatureKey);
        if ($publicKey === null) {
            return false;
        }

        try {
            ['header' => $header, 'claims' => $claims, 'signature' => $raw, 'signingInput' => $signingInput] = $this->decodeJwt($this->bearerToken($signature));
        } catch (Exception) {
            return false;
        }

        if (($header['alg'] ?? '') !== 'EdDSA') {
            return false;
        }

        if (\strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($raw, $signingInput, $publicKey)) {
            return false;
        }

        $now = time();

        if (!isset($claims['exp']) || $now >= $this->number($claims, 'exp') + self::LEEWAY) {
            return false;
        }

        if (isset($claims['iat']) && $now < $this->number($claims, 'iat') - self::LEEWAY) {
            return false;
        }

        $digest = $this->base64UrlEncode(hash('sha256', $payload, true));

        return hash_equals($digest, $this->text($claims, 'body_sha256'));
    }

    /**
     * The event type a delivery announces, e.g. ref.updated or
     * installation.deleted. Entire types its deliveries in the body rather
     * than a header.
     *
     * @throws Exception
     */
    public function getEventType(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (!\is_array($decoded)) {
            throw new Exception('Invalid payload.');
        }

        $envelope = $this->nested($decoded, 'event');

        return $this->text($envelope, 'type');
    }

    /**
     * Parses webhook delivery payload
     *
     * Entire wraps every event in a delivery envelope whose event.type is
     * authoritative. A ref delivery describes one ref, so it yields one
     * event; a tag ref yields none, since only branches deploy.
     *
     * @param  string  $event The event type, as getEventType() reads it
     * @param  string  $payload The raw delivery body
     * @return array<array<mixed>> Parsed payloads as json objects
     */
    public function getEvents(string $event, string $payload): array
    {
        $decoded = json_decode($payload, true);

        if ($decoded === null || !\is_array($decoded)) {
            throw new Exception('Invalid payload.');
        }

        $envelope = $this->nested($decoded, 'event');
        $type = $this->text($envelope, 'type') ?: $event;
        $eventPayload = $this->nested($envelope, 'payload');

        $delivery = [
            'deliveryId' => $this->text($decoded, 'delivery_id'),
            'installationId' => $this->text($decoded, 'installation_id'),
            'apiUrl' => rtrim($this->text($decoded, 'api_url'), '/'),
        ];

        if (\in_array($type, [self::EVENT_REF_CREATED, self::EVENT_REF_UPDATED, self::EVENT_REF_DELETED], true)) {
            return $this->refEvents($type, $eventPayload, $delivery);
        }

        if (str_starts_with($type, self::EVENT_INSTALLATION . '.')) {
            return $this->installationEvents($type, $eventPayload, $delivery);
        }

        return [];
    }

    /**
     * @param array<mixed> $payload
     * @param array{deliveryId: string, installationId: string, apiUrl: string} $delivery
     * @return array<array<mixed>>
     */
    protected function refEvents(string $type, array $payload, array $delivery): array
    {
        $ref = $this->text($payload, 'ref');
        if (!str_starts_with($ref, 'refs/heads/')) {
            return [];
        }

        $repository = $this->nested($payload, 'repository');
        [$owner, $repositoryName] = $this->splitPath($this->text($repository, 'path'));
        $repositoryId = $this->text($repository, 'id');
        $repositoryUrl = $owner !== '' && $repositoryName !== '' ? $this->getRepositoryUrl($owner, $repositoryName) : '';

        $branch = substr($ref, \strlen('refs/heads/'));
        $deleted = $type === self::EVENT_REF_DELETED;
        $commitHash = $deleted ? '' : $this->text($payload, 'after');

        $pusher = $this->nested($payload, 'pusher');
        $account = $this->nested($pusher, 'account');

        return [[
            'branchCreated' => $type === self::EVENT_REF_CREATED,
            'branchDeleted' => $deleted,
            'branch' => $branch,
            'branchUrl' => $repositoryUrl !== '' && $branch !== '' ? $this->getBranchUrl($owner, $repositoryName, $branch) : '',
            'repositoryId' => $repositoryId,
            'repositoryName' => $repositoryName,
            'repositoryUrl' => $repositoryUrl,
            'installationId' => $delivery['installationId'],
            'deliveryId' => $delivery['deliveryId'],
            'apiUrl' => $delivery['apiUrl'],
            'defaultBranch' => $this->text($repository, 'default_branch'),
            'commitHash' => $commitHash,
            'previousCommitHash' => $this->text($payload, 'before'),
            'forced' => (bool) ($payload['forced'] ?? false),
            'owner' => $owner,
            'authorUrl' => '',
            'authorAvatarUrl' => '',
            // A delivery names who pushed, not who authored the commit;
            // consumers read the commit for its author and message
            'headCommitAuthorName' => $this->text($account, 'handle'),
            'headCommitAuthorEmail' => '',
            'headCommitMessage' => '',
            'headCommitUrl' => $repositoryUrl !== '' && $commitHash !== '' ? $this->getCommitUrl($owner, $repositoryName, $commitHash) : '',
            'external' => false,
            'pullRequestNumber' => '',
            'action' => '',
            // Deliveries carry no file lists
            'affectedFiles' => [],
        ]];
    }

    /**
     * @param array<mixed> $payload
     * @param array{deliveryId: string, installationId: string, apiUrl: string} $delivery
     * @return array<array<mixed>>
     */
    protected function installationEvents(string $type, array $payload, array $delivery): array
    {
        $installation = $this->nested($payload, 'installation');

        return [[
            'action' => substr($type, \strlen(self::EVENT_INSTALLATION . '.')),
            'installationId' => $delivery['installationId'],
            'deliveryId' => $delivery['deliveryId'],
            'projectId' => $this->text($installation, 'project_id'),
            'repositorySelection' => $this->text($installation, 'repository_selection'),
            'addedRepositoryIds' => $this->strings($installation, 'added_repository_ids'),
            'removedRepositoryIds' => $this->strings($installation, 'removed_repository_ids'),
            'userName' => '',
        ]];
    }

    /**
     * Entire types its deliveries in the body, see getEventType(); no header
     * carries the type. This names a header a consumer may still log.
     */
    public function getEventHeaderName(): string
    {
        return 'x-entire-event';
    }

    /**
     * The delivery token travels as a bearer.
     */
    public function getSignatureHeaderName(): string
    {
        return 'authorization';
    }

    public function getSupportedWebhookScopes(): array
    {
        return [self::WEBHOOK_SCOPE_INSTALLATION];
    }

    /**
     * Create a webhook on a repository
     *
     * Entire delivers webhooks per plugin installation to the URL registered
     * on the plugin; repositories carry no hooks of their own.
     */
    public function createWebhook(string $owner, string $repositoryName, string $url, string $secret, array $events = ['push', 'pull_request']): int|string
    {
        throw new Exception('createWebhook() is not supported by Entire. Configure the webhook URL on the Entire plugin.');
    }

    /**
     * Get user
     *
     * @return array<mixed>
     */
    public function getUser(string $username): array
    {
        throw new Exception('getUser() is not supported by Entire');
    }

    /**
     * Get owner name of the installation: the project it was installed on.
     *
     * A plugin reads its installation only through the repositories it
     * covers, which all belong to the installing project. An installation
     * covering no repository yet names no owner.
     *
     * @param string $installationId Entire installation id
     * @param int|null $repositoryId Not used by Entire (parameter exists for adapter compatibility)
     */
    public function getOwnerName(string $installationId, ?int $repositoryId = null): string
    {
        foreach ($this->installationRepositories() as $repository) {
            return $this->text($repository, 'organization');
        }

        return '';
    }

    /**
     * Whether the installation covers every repository of its project. The
     * selection is readable by the project, not the plugin, and the
     * repository list is authoritative either way.
     */
    public function hasAccessToAllRepositories(): bool
    {
        return false;
    }

    /**
     * Search repositories the installation covers, filtered to the requested
     * owner (project) and name substring. Entire reports no totals, so the
     * list is collected once and page/per_page are applied locally.
     *
     * @return array{items: array<mixed>, total: int}
     */
    public function searchRepositories(string $owner, int $page, int $per_page, string $search = ''): array
    {
        $repositories = [];
        foreach ($this->installationRepositories() as $repository) {
            if ($owner !== '' && strtolower($this->text($repository, 'organization')) !== strtolower($owner)) {
                continue;
            }
            if ($search !== '' && stripos($this->text($repository, 'name'), $search) === false) {
                continue;
            }

            $repositories[] = $repository;
        }

        return [
            'items' => \array_slice($repositories, ($page - 1) * $per_page, $per_page),
            'total' => \count($repositories),
        ];
    }

    /**
     * Get repository for the installation
     *
     * @return array<mixed>
     */
    public function getInstallationRepository(string $repositoryName): array
    {
        foreach ($this->installationRepositories() as $repository) {
            if (strtolower($this->text($repository, 'name')) === strtolower($repositoryName)) {
                return $repository;
            }
        }

        throw new RepositoryNotFound('Repository not found.');
    }

    /**
     * Fetches repository name using repository id
     */
    public function getRepositoryName(string $repositoryId): string
    {
        foreach ($this->installationRepositories() as $repository) {
            if ($this->text($repository, 'id') === $repositoryId) {
                return $this->text($repository, 'name');
            }
        }

        throw new RepositoryNotFound('Repository not found.');
    }

    /**
     * Get Entire repository
     *
     * @return array<mixed>
     */
    public function getRepository(string $owner, string $repositoryName): array
    {
        $repository = $this->repository($owner, $repositoryName);
        $repository['default_branch'] = $this->defaultBranch($this->text($repository, 'id'));

        return $repository;
    }

    /**
     * Every repository the installation covers, in the shape the contract
     * promises, memoized per instance; a lookup by id or name scans it, since
     * a plugin addresses repositories only through its installation. The
     * customer changes the selection without notice, so the list is the
     * authority on what the plugin may touch.
     *
     * @return array<array<string, mixed>>
     */
    protected function installationRepositories(): array
    {
        if ($this->repositories !== null) {
            return $this->repositories;
        }

        $repositories = [];
        $cursor = '';

        do {
            $response = $this->call(
                self::METHOD_GET,
                '/plugins/' . rawurlencode($this->plugin) . '/installations/' . rawurlencode($this->installationId) . '/repositories',
                ['Authorization' => "Bearer {$this->jwtToken}"],
                $cursor === '' ? [] : ['cursor' => $cursor],
            );

            $statusCode = $this->status($response);
            if ($statusCode >= 400) {
                throw $this->requestFailed('Failed to list installation repositories', $response);
            }

            $responseBody = $this->body($response);
            foreach ($this->nested($responseBody, 'repositories') as $repository) {
                if (\is_array($repository)) {
                    $repositories[] = $this->normalizeRepository($repository);
                }
            }

            $nextCursor = $this->text($responseBody, 'next_cursor');
            if ($nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
        } while ($cursor !== '');

        return $this->repositories = $repositories;
    }

    /**
     * Reshapes a plugin repository: its project becomes the organization, its
     * processing-primary placement names the cell that serves it.
     *
     * @param array<mixed> $repository
     * @return array<string, mixed>
     */
    protected function normalizeRepository(array $repository): array
    {
        $fullName = $this->text($repository, 'full_name');
        [$owner, $name] = $this->splitPath($fullName);
        $id = $this->text($repository, 'id');

        $apiBaseUrl = '';
        foreach ($this->nested($repository, 'placements') as $placement) {
            if (!\is_array($placement)) {
                continue;
            }
            $candidate = rtrim($this->text($placement, 'api_base_url'), '/');
            if ($candidate === '') {
                continue;
            }
            $apiBaseUrl = $candidate;
            if (($placement['role'] ?? '') === 'processing_primary') {
                break;
            }
        }

        if ($id !== '' && $apiBaseUrl !== '' && !isset($this->dataEndpoints[$id])) {
            $this->dataEndpoints[$id] = $apiBaseUrl;
        }

        return [
            'id' => $id,
            'name' => $name,
            'full_name' => $fullName,
            'organization' => $owner,
            'clone_url' => $this->text($repository, 'clone_url'),
            'entire_clone_url' => $this->text($repository, 'entire_clone_url'),
            // Every Entire repository needs an authenticated reader
            'private' => true,
            'pushed_at' => '',
        ];
    }

    /**
     * The repository the installation covers under this owner and name.
     *
     * @return array<string, mixed>
     * @throws RepositoryNotFound
     */
    protected function repository(string $owner, string $repositoryName): array
    {
        foreach ($this->installationRepositories() as $repository) {
            if (
                strtolower($this->text($repository, 'organization')) === strtolower($owner)
                && strtolower($this->text($repository, 'name')) === strtolower($repositoryName)
            ) {
                return $repository;
            }
        }

        throw new RepositoryNotFound('Repository not found.');
    }

    protected function repositoryId(string $owner, string $repositoryName): string
    {
        return $this->text($this->repository($owner, $repositoryName), 'id');
    }

    /**
     * The owner and name a repository path carries: et/{project}/{repo} or
     * gh/{owner}/{repo}, with or without the forge prefix.
     *
     * @return array{string, string}
     */
    protected function splitPath(string $path): array
    {
        $path = trim($path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        $segments = explode('/', $path);
        if (\count($segments) >= 3) {
            $segments = \array_slice($segments, -2);
        }
        if (\count($segments) !== 2) {
            return ['', ''];
        }

        return [$segments[0], $segments[1]];
    }

    /**
     * Names the data-plane origin serving a repository, as a delivery
     * reports it, sparing the repository listing it would otherwise take.
     */
    public function setRepositoryEndpoint(string $repositoryId, string $apiBaseUrl): void
    {
        $apiBaseUrl = rtrim($apiBaseUrl, '/');
        if ($repositoryId !== '' && $apiBaseUrl !== '') {
            $this->dataEndpoints[$repositoryId] = $apiBaseUrl;
        }
    }

    /**
     * The data-plane origin serving a repository.
     */
    protected function dataEndpoint(string $repositoryId): string
    {
        if (!isset($this->dataEndpoints[$repositoryId])) {
            $this->installationRepositories();
        }

        if (!isset($this->dataEndpoints[$repositoryId])) {
            throw new RepositoryNotFound('Repository has no reachable placement.');
        }

        return $this->dataEndpoints[$repositoryId];
    }

    /**
     * An API call against the cell serving a repository, authenticated with
     * the installation token.
     *
     * @param array<mixed> $params
     * @return array<mixed>
     */
    protected function dataCall(string $repositoryId, string $method, string $path, array $params = [], bool $decode = true): array
    {
        return $this->callAt(
            $this->dataEndpoint($repositoryId) . '/api/v1',
            $method,
            $path,
            ['Authorization' => "Bearer {$this->accessToken}"],
            $params,
            $decode,
        );
    }

    /**
     * Entire answers errors as RFC 9457 problem documents, whose media type
     * the generic client leaves undecoded; their stable `code` is what a
     * caller branches on, so they are decoded here.
     *
     * @param array<string, string> $headers
     * @param array<mixed> $params
     * @return array<mixed>
     */
    #[\Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $response = parent::call($method, $path, $headers, $params, $decode, $followRedirects);

        $contentType = strtolower($this->text($this->nested($response, 'headers'), 'content-type'));
        if ($decode && \is_string($response['body'] ?? null) && str_starts_with($contentType, 'application/problem+json')) {
            $problem = json_decode($response['body'], true);
            if (\is_array($problem)) {
                $response['body'] = $problem;
            }
        }

        return $response;
    }

    /**
     * An API call against another base than the control plane.
     *
     * @param array<string, string> $headers
     * @param array<mixed> $params
     * @return array<mixed>
     */
    protected function callAt(string $base, string $method, string $path, array $headers = [], array $params = [], bool $decode = true): array
    {
        $endpoint = $this->endpoint;
        $this->endpoint = rtrim($base, '/');

        try {
            return $this->call($method, $path, $headers, $params, $decode);
        } finally {
            $this->endpoint = $endpoint;
        }
    }

    /**
     * The serving cell's record of a repository, memoized. The cell knows
     * the repository by its own id, which its git endpoints are addressed
     * by; the control-plane id addresses the rest.
     *
     * @return array<mixed>
     */
    protected function regionalRepository(string $repositoryId): array
    {
        if (isset($this->regionalRepositories[$repositoryId])) {
            return $this->regionalRepositories[$repositoryId];
        }

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/repos/' . rawurlencode($repositoryId));

        $statusCode = $this->status($response);
        if ($statusCode === 404) {
            throw new RepositoryNotFound('Repository not found.');
        }
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to get repository', $response);
        }

        return $this->regionalRepositories[$repositoryId] = $this->body($response);
    }

    /**
     * The id the serving cell's git endpoints address a repository by.
     */
    protected function gitRepositoryId(string $repositoryId): string
    {
        $id = $this->text($this->regionalRepository($repositoryId), 'id');

        return $id === '' ? $repositoryId : $id;
    }

    /**
     * The default branch of a repository.
     */
    protected function defaultBranch(string $repositoryId): string
    {
        return $this->text($this->regionalRepository($repositoryId), 'default_branch', 'main');
    }

    /**
     * Create new repository
     *
     * Creating a repository takes the project's manage grant, which an
     * installation token does not hold.
     *
     * @return array<mixed>
     */
    public function createRepository(string $owner, string $repositoryName, bool $private): array
    {
        throw new Exception('createRepository() is not supported by ' . $this->getName());
    }

    /**
     * Delete repository
     *
     * Deleting a repository takes the project's manage grant, which an
     * installation token does not hold.
     */
    public function deleteRepository(string $owner, string $repositoryName): bool
    {
        throw new Exception('deleteRepository() is not supported by ' . $this->getName());
    }

    #[\Override]
    public function supportsRepositoryCreation(): bool
    {
        return false;
    }

    #[\Override]
    public function supportsRepositoryDeletion(): bool
    {
        return false;
    }

    /**
     * Entire offers no archive downloads; consumers clone over Git HTTPS.
     */
    #[\Override]
    public function supportsRepositoryArchives(): bool
    {
        return false;
    }

    /**
     * Entire renders markdown without proxying images, so images on a
     * consumer's own host cannot display; comments should stay textual.
     */
    #[\Override]
    public function supportsCommentImages(): bool
    {
        return false;
    }

    /**
     * A public Entire repository is readable by any signed-in Entire user,
     * never anonymously.
     */
    #[\Override]
    public function supportsPublicRepositories(): bool
    {
        return false;
    }

    /**
     * Lists branches for a given repository
     *
     * @return array<string> List of branch names as array
     */
    public function listBranches(string $owner, string $repositoryName): array
    {
        try {
            $repositoryId = $this->repositoryId($owner, $repositoryName);
            $refs = $this->collectPages($repositoryId, '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/branches', 'branches');
        } catch (Exception) {
            // A missing repository, or one with no commits yet, has no branches
            return [];
        }

        return $this->refNames($refs);
    }

    /**
     * Lists tags for a given repository, optionally filtered by a glob pattern.
     *
     * @return array<string> List of tag names as array
     */
    public function listTags(string $owner, string $repositoryName, string $search = ''): array
    {
        try {
            $repositoryId = $this->repositoryId($owner, $repositoryName);
            $refs = $this->collectPages($repositoryId, '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/tags', 'tags');
        } catch (Exception) {
            return [];
        }

        return $this->matchGlob($this->refNames($refs), $search);
    }

    /**
     * The short names of listed refs.
     *
     * @param array<mixed> $refs
     * @return array<string>
     */
    protected function refNames(array $refs): array
    {
        $names = [];
        foreach ($refs as $ref) {
            $name = \is_array($ref) ? $this->text($ref, 'name') : (\is_string($ref) ? $ref : '');
            foreach (['refs/heads/', 'refs/tags/'] as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $name = substr($name, \strlen($prefix));
                }
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Get repository tree
     *
     * @return array<string> List of files in the repository
     */
    public function getRepositoryTree(string $owner, string $repositoryName, string $branch, bool $recursive = false): array
    {
        try {
            $repositoryId = $this->repositoryId($owner, $repositoryName);
        } catch (RepositoryNotFound) {
            return [];
        }

        $params = ['ref' => $branch];
        if ($recursive) {
            $params['recursive'] = 'true';
        }

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/tree', $params);

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            return [];
        }

        $responseBody = $this->body($response);

        $paths = [];
        foreach ($this->nested($responseBody, 'entries') as $entry) {
            if (\is_array($entry) && !empty($entry['path'])) {
                $paths[] = $this->text($entry, 'path');
            }
        }

        return $paths;
    }

    /**
     * Get repository languages
     *
     * Entire computes no per-repository language statistics, so the answer is
     * always empty rather than an error, letting consumers detect from files.
     *
     * @return array<mixed>
     */
    public function listRepositoryLanguages(string $owner, string $repositoryName): array
    {
        return [];
    }

    /**
     * List contents of the specified root directory.
     *
     * @return array<mixed> List of contents at the specified path
     */
    public function listRepositoryContents(string $owner, string $repositoryName, string $path = '', string $ref = ''): array
    {
        try {
            $repositoryId = $this->repositoryId($owner, $repositoryName);
        } catch (RepositoryNotFound) {
            return [];
        }

        $params = ['ref' => $ref === '' || $ref === '0' ? $this->defaultBranch($repositoryId) : $ref];
        $path = $this->normalizeRepositoryPath($path);
        if ($path !== '') {
            $params['path'] = $path;
        }

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/tree', $params);

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            return [];
        }

        $responseBody = $this->body($response);

        $contents = [];
        foreach ($this->nested($responseBody, 'entries') as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $entryPath = $this->text($entry, 'path');
            $contents[] = [
                'name' => basename($entryPath),
                'size' => $this->number($entry, 'size'),
                'type' => ($entry['type'] ?? 'blob') === 'tree' ? self::CONTENTS_DIRECTORY : self::CONTENTS_FILE,
            ];
        }

        return $contents;
    }

    /**
     * Get contents of the specified file.
     *
     * @return array<string, mixed> File details
     */
    public function getRepositoryContent(string $owner, string $repositoryName, string $path, string $ref = ''): array
    {
        try {
            $repositoryId = $this->repositoryId($owner, $repositoryName);
        } catch (RepositoryNotFound) {
            throw new FileNotFound();
        }

        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_GET,
            '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/raw',
            [
                'ref' => $ref === '' || $ref === '0' ? $this->defaultBranch($repositoryId) : $ref,
                'path' => $this->normalizeRepositoryPath($path),
            ],
            decode: false,
        );

        $statusCode = $this->status($response);
        if ($statusCode !== 200 || !\is_string($response['body'] ?? null)) {
            throw new FileNotFound();
        }

        return [
            'sha' => '',
            'size' => \strlen($response['body']),
            'content' => $response['body'],
        ];
    }

    /**
     * Get details of a commit using commit hash
     *
     * @return array<mixed> Details of the commit
     */
    public function getCommit(string $owner, string $repositoryName, string $commitHash): array
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/commits', ['ref' => $commitHash, 'pageSize' => 1]);

        $statusCode = $this->status($response);
        if ($statusCode === 404) {
            throw new RepositoryNotFound('Commit not found.');
        }
        if ($statusCode >= 400) {
            throw new Exception('Commit not found or inaccessible.', $statusCode);
        }

        $responseBody = $this->body($response);
        $commits = $this->nested($responseBody, 'commits');
        $commit = $this->nested($commits, 0);
        if ($commit === []) {
            throw new RepositoryNotFound('Commit not found.');
        }

        return $this->normalizeCommit($commit, $owner, $repositoryName);
    }

    /**
     * Get latest commit of a branch
     *
     * @return array<mixed> Details of the commit
     */
    public function getLatestCommit(string $owner, string $repositoryName, string $branch): array
    {
        try {
            return $this->getCommit($owner, $repositoryName, $branch);
        } catch (RepositoryNotFound) {
            throw new RepositoryNotFound("Branch not found: {$branch}");
        }
    }

    /**
     * @param array<mixed> $commit
     * @return array<mixed>
     */
    protected function normalizeCommit(array $commit, string $owner, string $repositoryName): array
    {
        $author = $this->nested($commit, 'author');
        $sha = $this->text($commit, 'sha');

        return [
            'commitAuthor' => $this->text($author, 'name', 'Unknown'),
            'commitAuthorEmail' => $this->text($author, 'email'),
            'commitMessage' => $this->text($commit, 'message', 'No message'),
            // Entire reports git identities, not linked accounts
            'commitAuthorAvatar' => '',
            'commitAuthorUrl' => '',
            'commitHash' => $sha,
            'commitUrl' => $sha === '' ? '' : $this->getCommitUrl($owner, $repositoryName, $sha),
        ];
    }

    /**
     * The files that differ between two commits, in the shape
     * getPullRequestFiles() reports: filename, status and, for a rename,
     * previous_filename.
     *
     * @return array<array<string, string>>
     */
    public function compareFiles(string $owner, string $repositoryName, string $base, string $head): array
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/compare', ['base' => $base, 'head' => $head]);

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to compare commits', $response);
        }

        $responseBody = $this->body($response);

        $files = [];
        foreach ($this->nested($responseBody, 'files') as $file) {
            if (!\is_array($file)) {
                continue;
            }

            $files[] = [
                'filename' => $this->text($file, 'path'),
                'status' => $this->text($file, 'status'),
                'previous_filename' => $this->text($file, 'previousPath'),
            ];
        }

        return $files;
    }

    /**
     * Get latest opened pull request with specific head branch
     *
     * @return array<mixed>
     */
    public function getPullRequestFromBranch(string $owner, string $repositoryName, string $branch): array
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $cursor = '';
        do {
            $params = ['status' => 'open', 'per_page' => 100];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $response = $this->dataCall($repositoryId, self::METHOD_GET, '/repos/' . rawurlencode($repositoryId) . '/trails', $params);

            $statusCode = $this->status($response);
            if ($statusCode >= 400) {
                throw $this->requestFailed('Failed to list trails', $response);
            }

            $responseBody = $this->body($response);
            foreach ($this->nested($responseBody, 'items') as $trail) {
                if (\is_array($trail) && $this->text($trail, 'branch') === $branch) {
                    return $this->normalizeTrail($trail);
                }
            }

            $nextCursor = $this->text($responseBody, 'next_cursor');
            if ($nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
        } while ($cursor !== '');

        return [];
    }

    /**
     * Get Pull Request
     *
     * @return array<mixed> The retrieved trail
     */
    public function getPullRequest(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/repos/' . rawurlencode($repositoryId) . '/trails/' . $pullRequestNumber);

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to get trail', $response);
        }

        return $this->normalizeTrail($this->body($response));
    }

    /**
     * Create a pull request: a trail linked to an existing branch.
     *
     * @return array<mixed> Created trail details
     */
    public function createPullRequest(string $owner, string $repositoryName, string $title, string $head, string $base, string $body = ''): array
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_POST,
            '/repos/' . rawurlencode($repositoryId) . '/trails',
            [
                'title' => $title,
                'body' => $body,
                'base' => $base,
                'branch_name' => $head,
                'branch_action' => 'link',
                'status' => 'open',
            ],
        );

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to create trail', $response);
        }

        $responseBody = $this->body($response);

        $trail = $this->nested($responseBody, 'trail');

        return $this->normalizeTrail($trail === [] ? $responseBody : $trail);
    }

    /**
     * Reshapes a trail to the pull request shape the contract promises.
     *
     * @param array<mixed> $trail
     * @return array<mixed>
     */
    protected function normalizeTrail(array $trail): array
    {
        $status = $this->text($trail, 'status');

        return [
            'id' => $this->text($trail, 'id'),
            'number' => $this->number($trail, 'number'),
            'title' => $this->text($trail, 'title'),
            'body' => '',
            'state' => $status === 'closed' ? 'closed' : 'open',
            'draft' => $status === 'draft',
            'merged' => !empty($trail['merged_at']),
            'head' => [
                'ref' => $this->text($trail, 'branch'),
                'sha' => $this->text($trail, 'head_sha'),
            ],
            'base' => [
                'ref' => $this->text($trail, 'base'),
                'sha' => $this->text($trail, 'base_sha'),
            ],
            'merge_base_sha' => $this->text($trail, 'merge_base_sha'),
        ];
    }

    /**
     * Get files changed in a pull request: the trail's head compared to the
     * base it branched from.
     *
     * @return array<mixed> List of files changed in the pull request
     */
    public function getPullRequestFiles(string $owner, string $repositoryName, int $pullRequestNumber): array
    {
        $trail = $this->getPullRequest($owner, $repositoryName, $pullRequestNumber);

        $head = $this->text($this->nested($trail, 'head'), 'sha');
        $base = $this->text($trail, 'merge_base_sha');
        if ($base === '') {
            $base = $this->text($this->nested($trail, 'base'), 'sha');
        }
        if ($base === '') {
            $base = $this->text($this->nested($trail, 'base'), 'ref');
        }
        if ($head === '' || $base === '') {
            return [];
        }

        return $this->compareFiles($owner, $repositoryName, $base, $head);
    }

    /**
     * Add Comment to Pull Request
     *
     * Starts a discussion on the trail. The comment id carries the trail
     * number, which the discussion endpoints are addressed by.
     */
    public function createComment(string $owner, string $repositoryName, int $pullRequestNumber, string $comment): string
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_POST,
            '/repos/' . rawurlencode($repositoryId) . '/trails/' . $pullRequestNumber . '/discussions',
            ['title' => $this->commentTitle($comment), 'body' => $comment],
        );

        $statusCode = $this->status($response);

        // A discussion is written by a user principal; a plugin's permissions
        // (contents, checks) do not reach it, so the refusal is reported as
        // no comment rather than a failure - check runs still carry the build.
        if ($statusCode === 403) {
            return '';
        }

        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to create comment', $response);
        }

        $responseBody = $this->body($response);
        $discussion = $this->nested($responseBody, 'discussion');
        if (empty($discussion['id'])) {
            throw new Exception('Comment creation response is missing discussion ID.');
        }

        return $pullRequestNumber . self::COMMENT_SEPARATOR . $this->text($discussion, 'id');
    }

    /**
     * A discussion needs a title; the comment's first line of text serves.
     */
    protected function commentTitle(string $comment): string
    {
        foreach (explode("\n", $comment) as $line) {
            $line = trim(str_replace(['#', '*', '_', '`', '|', '>'], '', $line));
            if ($line !== '') {
                return mb_strimwidth($line, 0, 80, '…');
            }
        }

        return 'Comment';
    }

    /**
     * The trail number and discussion id a comment id carries.
     *
     * @return array{int, string}
     */
    protected function parseCommentId(string $commentId): array
    {
        $separator = strpos($commentId, self::COMMENT_SEPARATOR);
        if ($separator === false) {
            throw new Exception('Comment id does not name a trail');
        }

        return [(int) substr($commentId, 0, $separator), substr($commentId, $separator + 1)];
    }

    /**
     * Get Comment of Pull Request: the opening message of the discussion.
     */
    public function getComment(string $owner, string $repositoryName, string $commentId): string
    {
        try {
            $message = $this->openingMessage($this->repositoryId($owner, $repositoryName), $commentId);
        } catch (Exception) {
            return '';
        }

        return $this->text($message, 'body');
    }

    /**
     * Update Pull Request Comment: edits the opening message of the discussion.
     */
    public function updateComment(string $owner, string $repositoryName, string $commentId, string $comment): string
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);
        [$number, $discussionId] = $this->parseCommentId($commentId);
        $message = $this->openingMessage($repositoryId, $commentId);

        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_PATCH,
            '/repos/' . rawurlencode($repositoryId) . '/trails/' . $number . '/discussions/' . rawurlencode($discussionId) . '/messages/' . rawurlencode($this->text($message, 'id')),
            ['body' => $comment],
        );

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to update comment', $response);
        }

        return $commentId;
    }

    /**
     * The message that opened a discussion.
     *
     * @return array<mixed>
     */
    protected function openingMessage(string $repositoryId, string $commentId): array
    {
        [$number, $discussionId] = $this->parseCommentId($commentId);

        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_GET,
            '/repos/' . rawurlencode($repositoryId) . '/trails/' . $number . '/discussions/' . rawurlencode($discussionId),
        );

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to get comment', $response);
        }

        $responseBody = $this->body($response);
        $messages = $this->nested($responseBody, 'messages');
        $message = $this->nested($messages, 0);
        if (empty($message['id'])) {
            throw new Exception('Discussion has no opening message.');
        }

        return $message;
    }

    /**
     * Updates status check of each commit
     * state can be one of: error, failure, pending, success
     */
    public function updateCommitStatus(string $repositoryName, string $SHA, string $owner, string $state, string $description = '', string $target_url = '', string $context = ''): void
    {
        $this->updateCheck($owner, $repositoryName, $SHA, $state, $context, $description, '', $target_url);
    }

    /**
     * Entire models CI feedback as check runs on a branch, so a check rides
     * the check run upsert with its name as the stable key - repeated reports
     * for one name land on one run, in a suite of the same name.
     */
    public function updateCheck(string $owner, string $repositoryName, string $SHA, string $state, string $name, string $title, string $summary = '', string $detailsUrl = ''): void
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_POST,
            '/repos/' . rawurlencode($repositoryId) . '/commits/' . rawurlencode($SHA) . '/check-runs',
            $this->checkRuns($this->branchContaining($repositoryId, $SHA), $state, $name, $title, $summary, $detailsUrl),
        );

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to update commit status', $response);
        }
    }

    /**
     * The check run write for one report: a suite and its single run, keyed
     * by the check's name and both carrying the state.
     *
     * @return array{branch: string, check_suite: array<string, mixed>, check_runs: list<array<string, mixed>>}
     */
    protected function checkRuns(string $branch, string $state, string $name, string $title, string $summary, string $detailsUrl): array
    {
        $key = $name === '' || $name === '0' ? 'default' : $name;
        $now = $this->rfc3339Now();
        [$status, $conclusion] = self::CHECK_STATES[$state] ?? ['completed', 'failure'];

        $run = [
            'key' => $key,
            'name' => $key,
            'status' => $status,
            // The name is the run's identity, so its attempt id can stay stable too
            'external_id' => $key,
            'external_updated_at' => $now,
        ];
        // A suite reports status and external_updated_at together or not at all
        $suite = $run;

        if ($conclusion !== null) {
            $run['conclusion'] = $suite['conclusion'] = $conclusion;
            $run['completed_at'] = $suite['completed_at'] = $now;
        }

        if ($title !== '') {
            $run['output'] = ['title' => mb_strimwidth($title, 0, 255), 'summary' => $summary === '' ? $title : $summary];
        }

        // Entire takes only an https details URL without credentials; a
        // plain-http one (a local console) is left off rather than refused
        if (str_starts_with($detailsUrl, 'https://') && !str_contains(substr($detailsUrl, 8), '@')) {
            $run['details_url'] = $suite['details_url'] = $detailsUrl;
        }

        return [
            'branch' => $branch,
            'check_suite' => $suite,
            'check_runs' => [$run],
        ];
    }

    /**
     * A branch holding a commit, which a check run write must name.
     */
    protected function branchContaining(string $repositoryId, string $commitHash): string
    {
        $response = $this->dataCall(
            $repositoryId,
            self::METHOD_POST,
            '/git/repos/' . rawurlencode($this->gitRepositoryId($repositoryId)) . '/branches-containing',
            ['targets' => [$commitHash]],
        );

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to find the branch of a commit', $response);
        }

        $responseBody = $this->body($response);
        $branches = $this->nested($responseBody, $commitHash);
        $branch = $this->text($branches, 0);
        if ($branch === '') {
            throw new Exception("No branch contains commit {$commitHash}");
        }

        return str_starts_with($branch, 'refs/heads/') ? substr($branch, \strlen('refs/heads/')) : $branch;
    }

    /**
     * Get commit statuses
     *
     * Reports the commit's check runs in commit-status shape, mirroring
     * updateCommitStatus()'s mapping.
     *
     * @return array<mixed>
     */
    public function getCommitStatuses(string $owner, string $repositoryName, string $commitHash): array
    {
        $repositoryId = $this->repositoryId($owner, $repositoryName);

        $response = $this->dataCall($repositoryId, self::METHOD_GET, '/repos/' . rawurlencode($repositoryId) . '/commits/' . rawurlencode($commitHash) . '/check-runs');

        $statusCode = $this->status($response);
        if ($statusCode >= 400) {
            throw $this->requestFailed('Failed to list check runs', $response);
        }

        $responseBody = $this->body($response);

        $statuses = [];
        foreach ($this->nested($responseBody, 'check_runs') as $checkRun) {
            if (!\is_array($checkRun)) {
                continue;
            }

            $conclusion = $this->text($checkRun, 'conclusion');
            $state = match (true) {
                ($checkRun['status'] ?? '') !== 'completed' => 'pending',
                \in_array($conclusion, ['success', 'neutral', 'skipped'], true) => 'success',
                default => 'failure',
            };

            $output = $this->nested($checkRun, 'output');

            $statuses[] = [
                'state' => $state,
                'description' => $this->text($output, 'title'),
                'target_url' => $this->text($checkRun, 'details_url'),
                'context' => $this->text($checkRun, 'key') ?: $this->text($checkRun, 'name'),
            ];
        }

        return $statuses;
    }

    /**
     * RFC 3339 timestamp with microseconds, so consecutive check run updates
     * order by their external_updated_at.
     */
    protected function rfc3339Now(): string
    {
        $now = \DateTime::createFromFormat('U.u', \sprintf('%.6F', microtime(true)));

        return $now !== false ? $now->format('Y-m-d\TH:i:s.u\Z') : gmdate('Y-m-d\TH:i:s\Z');
    }

    public function getRepositoryUrl(string $owner, string $repositoryName): string
    {
        return "{$this->webEndpoint}/et/{$owner}/{$repositoryName}";
    }

    public function getOrganizationUrl(string $owner): string
    {
        return "{$this->webEndpoint}/et/{$owner}";
    }

    public function getBranchUrl(string $owner, string $repositoryName, string $branch): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/tree/{$branch}";
    }

    public function getCommitUrl(string $owner, string $repositoryName, string $commitHash): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/commit/{$commitHash}";
    }

    public function getFileUrl(string $owner, string $repositoryName, string $reference): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/blob/{$reference}";
    }

    public function getPullRequestUrl(string $owner, string $repositoryName, int $pullRequestNumber): string
    {
        return $this->getRepositoryUrl($owner, $repositoryName) . "/trails/{$pullRequestNumber}";
    }

    /**
     * Generates a clone command using the installation access token.
     *
     * The entire:// remote is served by git-remote-entire, which git runs
     * from the PATH and which reads the token from ENTIRE_TOKEN. Exporting
     * it first puts it in the environment of every git command the chain
     * runs, including the ones the sparse checkout runs through a shell.
     */
    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): Command
    {
        return $this->withToken($this->cloneCommand($this->getRepositoryCloneUrl($owner, $repositoryName), $version, $versionType, $directory, $rootDirectory));
    }

    /**
     * Pushes a branch of a clone made by generateCloneCommand() back to its
     * origin, with the token the remote helper needs.
     */
    #[\Override]
    public function generatePushCommand(string $owner, string $repositoryName, string $directory, string $branch): Command
    {
        return $this->withToken(parent::generatePushCommand($owner, $repositoryName, $directory, $branch));
    }

    /**
     * The entire:// address of a repository, which only git-remote-entire
     * can fetch - see supportsRepositoryCloneUrl().
     */
    public function getRepositoryCloneUrl(string $owner, string $repositoryName): string
    {
        $repository = $this->repository($owner, $repositoryName);

        $entireUrl = $this->text($repository, 'entire_clone_url');
        if ($entireUrl !== '') {
            return $entireUrl;
        }

        // The HTTPS address names the same cluster host and path
        $cloneUrl = $this->text($repository, 'clone_url');
        if (str_starts_with($cloneUrl, 'https://')) {
            $cloneUrl = substr($cloneUrl, \strlen('https://'));

            return 'entire://' . (str_ends_with($cloneUrl, '.git') ? substr($cloneUrl, 0, -4) : $cloneUrl);
        }

        throw new Exception('Repository reports no clone URL.');
    }

    /**
     * Entire serves git only through its own remote helper, with the token
     * in the environment, so no plain git client can fetch the clone URL.
     */
    #[\Override]
    public function supportsRepositoryCloneUrl(): bool
    {
        return false;
    }

    /**
     * Runs a command with the installation token where git-remote-entire
     * reads it. Without a token the command runs as it is.
     */
    protected function withToken(Command $command): Command
    {
        if ($this->accessToken === '' || $this->accessToken === '0') {
            return $command;
        }

        return Command::and(
            new Command('export')->argument('ENTIRE_TOKEN=' . $this->accessToken),
            $command,
        );
    }

    /**
     * The repository path a caller-supplied file path names, normalized
     * lexically. Rejects a path that climbs out of the checkout through
     * `..` segments, which would otherwise write onto the host filesystem.
     */
    protected function confinedPath(string $filepath): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $filepath)) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (array_pop($segments) === null) {
                    throw new Exception("File path escapes the repository: {$filepath}");
                }
                continue;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new Exception("File path names no file: {$filepath}");
        }

        return implode('/', $segments);
    }

    /**
     * Create a file in a repository
     *
     * Entire has no contents-write API - content changes travel over git -
     * so this commits the file locally and pushes it through
     * git-remote-entire, which requires both binaries.
     *
     * @return array<mixed>
     */
    public function createFile(string $owner, string $repositoryName, string $filepath, string $content, string $message = 'Add file', string $branch = ''): array
    {
        $relative = $this->confinedPath($filepath);

        // Also resolves the default branch and confirms the repository exists
        $repository = $this->getRepository($owner, $repositoryName);
        $defaultBranch = $this->text($repository, 'default_branch', 'main');
        $targetBranch = $branch === '' || $branch === '0' ? $defaultBranch : $branch;

        $remote = $this->getRepositoryCloneUrl($owner, $repositoryName);
        $directory = $this->temporaryDirectory();

        try {
            $this->execute($this->git($directory)->argument('init')->flag('-q'), 'Initializing the working repository');

            // Base the commit on the branch tip when there is one; a missing
            // target branch grows from the default branch, and an empty
            // repository starts from scratch
            $hasBase = $this->tryExecute($this->withToken($this->git($directory)->argument('fetch')->flag('-q')->option('--depth', '1')->argument($remote)->argument('refs/heads/' . $targetBranch)))
                || (!\in_array($branch, ['', '0', $defaultBranch], true) && $this->tryExecute($this->withToken($this->git($directory)->argument('fetch')->flag('-q')->option('--depth', '1')->argument($remote)->argument('refs/heads/' . $defaultBranch))));
            if ($hasBase) {
                $this->execute($this->git($directory)->argument('checkout')->flag('-q')->argument('FETCH_HEAD'), 'Checking out the branch tip');
            }

            $absolute = $directory . '/' . $relative;
            $parent = \dirname($absolute);
            if (!is_dir($parent) && !mkdir($parent, 0777, true)) {
                throw new Exception("Failed to create directory for {$filepath}");
            }

            // Lexical confinement is not enough once the checkout itself
            // carries symlinks: a linked directory or file would carry the
            // write outside, so resolve the parent and refuse link targets
            $checkout = realpath($directory);
            $resolvedParent = realpath($parent);
            if ($checkout === false || $resolvedParent === false
                || !str_starts_with($resolvedParent . '/', $checkout . '/')
                || is_link($absolute)) {
                throw new Exception("File path escapes the repository: {$filepath}");
            }

            if (file_put_contents($absolute, $content) === false) {
                throw new Exception("Failed to write {$filepath}");
            }

            $this->execute($this->git($directory)->argument('add')->argument('--')->argument($relative), 'Staging the file');
            $this->execute($this->git($directory)->option('-c', 'user.name=Utopia VCS')->option('-c', 'user.email=vcs@utopia.dev')->argument('commit')->flag('-q')->option('-m', $message), 'Committing the file');
            $commitHash = trim($this->execute($this->git($directory)->argument('rev-parse')->argument('HEAD'), 'Reading the commit hash'));
            $this->execute($this->withToken($this->git($directory)->argument('push')->flag('-q')->argument($remote)->argument('HEAD:refs/heads/' . $targetBranch)), 'Pushing the commit');

            return [
                'path' => $relative,
                'branch' => $targetBranch,
                'commitHash' => $commitHash,
            ];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Create a branch in a repository
     *
     * Pushed through git-remote-entire; the remote already holds the
     * objects, so only a ref update travels.
     *
     * @return array<mixed>
     */
    public function createBranch(string $owner, string $repositoryName, string $newBranchName, string $oldBranchName): array
    {
        $remote = $this->getRepositoryCloneUrl($owner, $repositoryName);
        $directory = $this->temporaryDirectory();

        try {
            $this->execute($this->git($directory)->argument('init')->flag('-q'), 'Initializing the working repository');
            $this->execute($this->withToken($this->git($directory)->argument('fetch')->flag('-q')->option('--depth', '1')->argument($remote)->argument('refs/heads/' . $oldBranchName)), "Fetching branch {$oldBranchName}");
            $sha = trim($this->execute($this->git($directory)->argument('rev-parse')->argument('FETCH_HEAD'), 'Reading the branch tip'));
            $this->execute($this->withToken($this->git($directory)->argument('push')->flag('-q')->argument($remote)->argument('FETCH_HEAD:refs/heads/' . $newBranchName)), "Creating branch {$newBranchName}");

            return [
                'name' => $newBranchName,
                'ref' => 'refs/heads/' . $newBranchName,
                'sha' => $sha,
            ];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Create a tag in a repository
     *
     * Pushed through git-remote-entire. A message produces an annotated
     * tag, no message a lightweight one.
     *
     * @return array<mixed>
     */
    public function createTag(string $owner, string $repositoryName, string $tagName, string $target, string $message = ''): array
    {
        $remote = $this->getRepositoryCloneUrl($owner, $repositoryName);
        $directory = $this->temporaryDirectory();

        try {
            $this->execute($this->git($directory)->argument('init')->flag('-q'), 'Initializing the working repository');

            // Prefer fetching the target object directly; fall back to every
            // branch head for servers that refuse fetch-by-SHA
            if (!$this->tryExecute($this->withToken($this->git($directory)->argument('fetch')->flag('-q')->option('--depth', '1')->argument($remote)->argument($target)))) {
                $this->execute($this->withToken($this->git($directory)->argument('fetch')->flag('-q')->argument($remote)->argument('refs/heads/*:refs/remotes/origin/*')), "Fetching commit {$target}");
            }

            if ($message !== '' && $message !== '0') {
                $this->execute(
                    $this->git($directory)->option('-c', 'user.name=Utopia VCS')->option('-c', 'user.email=vcs@utopia.dev')->argument('tag')->flag('-a')->argument($tagName)->option('-m', $message)->argument($target),
                    "Creating tag {$tagName}",
                );
                $this->execute($this->withToken($this->git($directory)->argument('push')->flag('-q')->argument($remote)->argument('refs/tags/' . $tagName)), "Pushing tag {$tagName}");
            } else {
                $this->execute($this->withToken($this->git($directory)->argument('push')->flag('-q')->argument($remote)->argument($target . ':refs/tags/' . $tagName)), "Pushing tag {$tagName}");
            }

            return [
                'name' => $tagName,
                'sha' => $target,
                'message' => $message,
            ];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/utopia-vcs-entire-' . uniqid();
        if (!mkdir($directory, 0777, true)) {
            throw new Exception('Failed to create a temporary directory for the Git operation.');
        }

        return $directory;
    }

    protected function removeDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $stdout = '';
            $stderr = '';
            Console::execute(new Command('rm')->flag('-rf')->argument($directory), '', $stdout, $stderr);
        }
    }

    /**
     * Runs a shell command and throws on failure, with the access token
     * scrubbed out of whatever the command printed.
     *
     * @return string What the command printed to stdout
     */
    protected function execute(Command $command, string $action): string
    {
        $stdout = '';
        $stderr = '';
        $exitCode = Console::execute($command, '', $stdout, $stderr);

        if ($exitCode !== 0) {
            $printed = trim($stdout . "\n" . $stderr);
            if ($this->accessToken !== '' && $this->accessToken !== '0') {
                $printed = str_replace($this->accessToken, '***', $printed);
            }

            throw new Exception("{$action} failed: {$printed}");
        }

        return $stdout;
    }

    protected function tryExecute(Command $command): bool
    {
        $stdout = '';
        $stderr = '';

        return Console::execute($command, '', $stdout, $stderr) === 0;
    }

    /**
     * Collects every page of a token-paginated data-plane collection.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    protected function collectPages(string $repositoryId, string $path, string $field, array $params = []): array
    {
        $items = [];
        $pageToken = '';

        do {
            $query = array_merge($params, ['pageSize' => 100]);
            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->dataCall($repositoryId, self::METHOD_GET, $path, $query);

            $statusCode = $this->status($response);
            if ($statusCode >= 400) {
                throw $this->requestFailed("Failed to list {$field}", $response);
            }

            $responseBody = $this->body($response);
            $items = array_merge($items, $this->nested($responseBody, $field));

            // A next-page token that fails to advance would page forever
            $nextPageToken = $this->text($responseBody, 'nextPageToken');
            if ($nextPageToken === $pageToken) {
                break;
            }
            $pageToken = $nextPageToken;
        } while ($pageToken !== '');

        return $items;
    }

    /**
     * The status code of a call.
     *
     * @param array<mixed> $response
     */
    protected function status(array $response): int
    {
        $status = $this->nested($response, 'headers')['status-code'] ?? 0;

        return \is_int($status) ? $status : 0;
    }

    /**
     * The decoded JSON object a call answered with, or nothing.
     *
     * @param array<mixed> $response
     * @return array<mixed>
     */
    protected function body(array $response): array
    {
        return $this->nested($response, 'body');
    }

    /**
     * A nested object of a decoded JSON value, or nothing.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    protected function nested(array $data, string|int $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /**
     * A text field of a decoded JSON value, $default when absent or not scalar.
     *
     * @param array<mixed> $data
     */
    protected function text(array $data, string|int $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A numeric field of a decoded JSON value, 0 when absent or not numeric.
     *
     * @param array<mixed> $data
     */
    protected function number(array $data, string|int $key): int
    {
        $value = $data[$key] ?? null;

        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The string elements of a list field of a decoded JSON value.
     *
     * @param array<mixed> $data
     * @return array<string>
     */
    protected function strings(array $data, string $key): array
    {
        $strings = [];
        foreach ($this->nested($data, $key) as $value) {
            if (\is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }

    /**
     * Builds the exception for a failed call, carrying Entire's own problem
     * detail when it sent one - its RFC 9457 bodies name a stable code.
     *
     * @param array<mixed> $response
     */
    protected function requestFailed(string $action, array $response): Exception
    {
        $statusCode = $this->status($response);
        $body = $this->body($response);
        $message = trim($this->text($body, 'code') . ' ' . ($this->text($body, 'detail') ?: $this->text($body, 'title')));

        return new Exception(
            "{$action}: HTTP {$statusCode}" . ($message !== '' ? " ({$message})" : ''),
            $statusCode,
        );
    }
}
