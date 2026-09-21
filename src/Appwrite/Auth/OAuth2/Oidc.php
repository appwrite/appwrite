<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://openid.net/connect/faq/
// https://developers.cloudflare.com/cloudflare-one/access-controls/applications/http-apps/saas-apps/generic-oidc-saas/

class Oidc extends OAuth2
{
    /**
     * @var array
     */
    protected array $scopes = [
        'openid',
        'profile',
        'email',
    ];

    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'oidc';
    }

    protected array $wellKnownConfiguration = [];

    /**
     * Memoized decode of the JSON stored in appSecret. Building a single login
     * URL reads the secret several times (prompt, max_age, each endpoint), so
     * decode once and reuse.
     *
     * @var array|null
     */
    protected ?array $decodedAppSecret = null;

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        $params = [
            'client_id' => $this->appID,
            'redirect_uri' => $this->callback,
            'state' => \json_encode($this->state),
            'scope' => \implode(' ', $this->getScopes()),
            'response_type' => 'code',
        ];

        $prompt = $this->getPrompt();
        if (!empty($prompt)) {
            $params['prompt'] = $prompt;
        }

        $maxAge = $this->getMaxAge();
        if ($maxAge !== null) {
            $params['max_age'] = $maxAge;
        }

        return $this->getAuthorizationEndpoint() . '?' . \http_build_query($params);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            $this->tokens = $this->parseTokens($this->request(
                'POST',
                $this->getTokenEndpoint(),
                $headers,
                \http_build_query([
                    'code' => $code,
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'redirect_uri' => $this->callback,
                    'scope' => \implode(' ', $this->getScopes()),
                    'grant_type' => 'authorization_code'
                ])
            ));
        }
        return $this->tokens;
    }


    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $this->tokens = $this->parseTokens($this->request(
            'POST',
            $this->getTokenEndpoint(),
            $headers,
            \http_build_query([
                'refresh_token' => $refreshToken,
                'client_id' => $this->appID,
                'client_secret' => $this->getClientSecret(),
                'grant_type' => 'refresh_token'
            ])
        ));

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * Parse a token-endpoint body. Cloudflare Access (and some other IdPs) return
     * an HTTP redirect with an empty body on error instead of a JSON error object;
     * curl does not follow that redirect, so we get "" and must not treat it as
     * a successful empty token set.
     *
     * @return array<string, mixed>
     */
    private function parseTokens(string $response): array
    {
        if ($response === '') {
            throw new Exception(\json_encode([
                'error' => 'token_response_empty',
                'error_description' => 'OIDC token endpoint returned an empty body. Providers that answer errors with an HTTP redirect (and no JSON) are not supported for the token exchange.',
            ]), 400);
        }

        $tokens = \json_decode($response, true);

        if (!\is_array($tokens)) {
            $tokens = [];
            \parse_str($response, $tokens);
        }

        if (isset($tokens['error'])) {
            throw new Exception(\json_encode(
                $tokens,
                JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            ), 400);
        }

        // Authorization-code responses normally include access_token; some OPs
        // (and hybrid-adjacent setups) may still assert identity via id_token alone.
        if (empty($tokens['access_token']) && empty($tokens['id_token'])) {
            throw new Exception(\json_encode([
                'error' => 'access_token_missing',
                'error_description' => 'OIDC token endpoint did not return an access token or ID token.',
            ]), 400);
        }

        return $tokens;
    }

    /**
     * Decode the ID token payload without verifying the signature. The token was
     * just received over TLS from the admin-configured token endpoint (same trust
     * model as Apple/Zoho adapters). Signature verification for the native ID
     * token route lives in Appwrite\Auth\OIDC\IdTokenVerifier.
     *
     * @return array<string, mixed>
     */
    private function getIdTokenClaims(): array
    {
        $idToken = $this->tokens['id_token'] ?? '';
        if (!\is_string($idToken) || $idToken === '') {
            return [];
        }

        $parts = \explode('.', $idToken);
        if (\count($parts) < 2) {
            return [];
        }

        $payload = $parts[1];
        $padding = (4 - (\strlen($payload) % 4)) % 4;
        $json = \base64_decode(\strtr($payload, '-_', '+/') . \str_repeat('=', $padding), true);
        if ($json === false) {
            return [];
        }

        $claims = \json_decode($json, true);

        return \is_array($claims) ? $claims : [];
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['sub'])) {
            return $user['sub'];
        }

        return '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['email'])) {
            return $user['email'];
        }

        return '';
    }

    /**
     * Check if the User email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if (\array_key_exists('email_verified', $user)) {
            // Apple (and some IdPs) attest the claim as the string "true"; Google as a boolean.
            return \filter_var($user['email_verified'], FILTER_VALIDATE_BOOLEAN);
        }

        // The project admin trusts this OP. Enterprise IdPs — notably Cloudflare
        // Access SaaS OIDC — often omit email_verified after authenticating the
        // user while still asserting `email`. An explicit false above still blocks
        // account linking. Without this, existing Appwrite users matching that
        // email fail the OAuth callback with a generic general_bad_request.
        return !empty($user['email']);
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        if (isset($user['name'])) {
            return $user['name'];
        }

        return '';
    }

    /**
    * @param string $accessToken
    *
    * @return array
    */
    protected function getUser(string $accessToken): array
    {
        if (!empty($this->user)) {
            return $this->user;
        }

        // Start from ID token claims so identity still works when userinfo is
        // slow, unavailable, or redundant (Cloudflare Access puts claims on the ID token).
        $claims = $this->getIdTokenClaims();

        if ($accessToken !== '') {
            $endpoint = $this->getUserinfoEndpoint();
            if ($endpoint !== '') {
                try {
                    $headers = ['Authorization: Bearer ' . \urlencode($accessToken)];
                    $user = $this->request('GET', $endpoint, $headers);
                    $decoded = \json_decode($user, true);
                    if (\is_array($decoded)) {
                        // Userinfo overrides the ID token for overlapping keys (OIDC Core).
                        $claims = \array_merge($claims, $decoded);
                    }
                } catch (Exception $exception) {
                    if (empty($claims)) {
                        throw $exception;
                    }
                }
            }
        }

        if (empty($claims)) {
            throw new Exception(\json_encode([
                'error' => 'userinfo_missing',
                'error_description' => 'OIDC provider did not return user claims from the ID token or userinfo endpoint.',
            ]), 400);
        }

        $this->user = $claims;

        return $this->user;
    }

    /**
     * Extracts the Client Secret from the JSON stored in appSecret
     *
     * @return string
     */
    protected function getClientSecret(): string
    {
        $secret = $this->getAppSecret();

        return $secret['clientSecret'] ?? '';
    }

    /**
     * Extracts the prompt values from the JSON stored in appSecret.
     *
     * @return string
     */
    protected function getPrompt(): string
    {
        $secret = $this->getAppSecret();
        $prompt = $secret['prompt'] ?? [];

        if (!\is_array($prompt)) {
            $prompt = [$prompt];
        }

        return \implode(' ', $prompt);
    }

    /**
     * Extracts the max_age value from the JSON stored in appSecret.
     *
     * @return int|null
     */
    protected function getMaxAge(): ?int
    {
        $secret = $this->getAppSecret();
        $maxAge = $secret['maxAge'] ?? null;

        if ($maxAge === null || $maxAge === '') {
            return null;
        }

        return (int) $maxAge;
    }

    /**
    * Extracts the well known endpoint from the JSON stored in appSecret.
    *
    * @return string
    */
    protected function getWellKnownEndpoint(): string
    {
        $secret = $this->getAppSecret();
        return $secret['wellKnownEndpoint'] ?? '';
    }

    /**
    * Extracts the authorization endpoint from the JSON stored in appSecret.
    *
    * If one is not provided, it will be retrieved from the well-known configuration.
     *
     * @return string
     */
    protected function getAuthorizationEndpoint(): string
    {
        $secret = $this->getAppSecret();

        $endpoint = $secret['authorizationEndpoint'] ?? '';
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $wellKnownConfiguration = $this->getWellKnownConfiguration();
        return $wellKnownConfiguration['authorization_endpoint'] ?? '';
    }

    /**
    * Extracts the token endpoint from the JSON stored in appSecret.
    *
    * If one is not provided, it will be retrieved from the well-known configuration.
    *
    * @return string
    */
    protected function getTokenEndpoint(): string
    {
        $secret = $this->getAppSecret();

        $endpoint = $secret['tokenEndpoint'] ?? '';
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $wellKnownConfiguration = $this->getWellKnownConfiguration();
        return $wellKnownConfiguration['token_endpoint'] ?? '';
    }

    /**
    * Extracts the userinfo endpoint from the JSON stored in appSecret.
    *
    * If one is not provided, it will be retrieved from the well-known configuration.
    *
    * @return string
    */
    protected function getUserinfoEndpoint(): string
    {
        $secret = $this->getAppSecret();
        // Read the legacy lowercase `userinfoEndpoint` key as a fallback so that
        // OIDC configs stored via the old generic oauth2 PATCH endpoint keep working.
        $endpoint = $secret['userInfoEndpoint'] ?? $secret['userinfoEndpoint'] ?? '';
        if (!empty($endpoint)) {
            return $endpoint;
        }

        $wellKnownConfiguration = $this->getWellKnownConfiguration();
        return $wellKnownConfiguration['userinfo_endpoint'] ?? '';
    }

    /**
     * Get the well-known configuration using the well known endpoint
     */
    protected function getWellKnownConfiguration(): array
    {
        if (empty($this->wellKnownConfiguration)) {
            $response = $this->request('GET', $this->getWellKnownEndpoint());
            if (empty($response)) {
                throw new Exception('Invalid well-known configuration');
            }
            $this->wellKnownConfiguration = \json_decode($response, true);
        }

        return $this->wellKnownConfiguration;
    }

    /**
     * Decode the JSON stored in appSecret
     *
     * @return array
     */
    protected function getAppSecret(): array
    {
        if ($this->decodedAppSecret !== null) {
            return $this->decodedAppSecret;
        }

        try {
            $secret = \json_decode($this->appSecret, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $th) {
            throw new \Exception('Invalid secret');
        }

        return $this->decodedAppSecret = \is_array($secret) ? $secret : [];
    }
}
