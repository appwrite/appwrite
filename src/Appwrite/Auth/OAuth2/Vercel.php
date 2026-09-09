<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;
use Utopia\Fetch\Client as FetchClient;

// Reference Material
// https://vercel.com/docs/integrations/create-integration/vercel-api-integrations
// https://vercel.com/docs/rest-api/user/get-the-user

class Vercel extends OAuth2
{
    private string $endpoint = 'https://api.vercel.com';

    protected array $user = [];

    protected array $tokens = [];

    /**
     * Scopes are not applicable to the Vercel API Integrations flow.
     * Permissions are configured statically in the Vercel Integration Console,
     * not passed as URL parameters at authorize time.
     */
    protected array $scopes = [];

    public function getName(): string
    {
        return 'vercel';
    }

    public function getLoginURL(): string
    {
        return 'https://vercel.com/integrations/'.\urlencode($this->getSlug()).'/new?'.\http_build_query([
            'state' => \json_encode($this->state),
        ]);
    }

    /**
     * Decode the JSON-packed secret blob `{"clientSecret":"...","slug":"..."}`.
     * Returns the raw appSecret string under key 'clientSecret' as a fallback
     * for installs that stored a plain secret before the slug field was added.
     */
    protected function getAppSecret(): array
    {
        try {
            $decoded = \json_decode($this->appSecret, true, 512, JSON_THROW_ON_ERROR);
            if (\is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable) {
        }

        return ['clientSecret' => $this->appSecret];
    }

    /**
     * Get the Vercel integration slug from the JSON-packed secret blob.
     * Throws when the slug is absent so that a misconfigured provider fails
     * loudly at login time instead of silently producing a 404 URL.
     *
     * @throws Exception
     */
    protected function getSlug(): string
    {
        $slug = $this->getAppSecret()['slug'] ?? '';
        if (empty($slug)) {
            throw new Exception('Vercel integration slug is not configured. Please provide the integration slug in the OAuth2 settings.', 400);
        }

        return $slug;
    }

    /**
     * Get the plain client secret from the JSON-packed secret blob.
     */
    protected function getClientSecret(): string
    {
        return $this->getAppSecret()['clientSecret'] ?? '';
    }

    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            $this->tokens = \json_decode($this->request(
                'POST',
                $this->endpoint.'/v2/oauth/access_token',
                ['Content-Type: application/x-www-form-urlencoded'],
                \http_build_query([
                    'client_id' => $this->appID,
                    'client_secret' => $this->getClientSecret(),
                    'code' => $code,
                    'redirect_uri' => $this->callback,
                ])
            ), true);
        }

        if (! isset($this->tokens['access_token'])) {
            throw new Exception('Vercel did not return a valid access token.', 400);
        }

        return $this->tokens;
    }

    /**
     * Vercel's /v2/oauth/access_token endpoint does not document a
     * refresh_token grant, and the documented token response includes
     * no refresh_token or expiry field. Treating the access token as
     * non-expiring here; this should be revisited if Vercel's docs
     * change or if this is confirmed/denied via direct testing.
     */
    public function refreshTokens(string $refreshToken): array
    {
        $this->tokens = [
            'access_token' => $refreshToken,
            'refresh_token' => $refreshToken,
        ];

        return $this->tokens;
    }

    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['id'] ?? '';
    }

    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['email'] ?? '';
    }

    /**
     * Vercel's /v2/user endpoint does not return an email_verified signal.
     */
    public function isEmailVerified(string $accessToken): bool
    {
        return false;
    }

    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['user']['name'] ?? $user['user']['username'] ?? '';
    }

    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            $response = \json_decode($this->request(
                'GET',
                $this->endpoint.'/v2/user',
                ['Authorization: Bearer '.$accessToken]
            ), true);

            if (! \is_array($response['user'] ?? null)) {
                throw new Exception('Vercel did not return valid user information.', 400);
            }

            $this->user = $response;
        }

        return $this->user;
    }

    public function verifyCredentials(): void
    {
        $client = new FetchClient;
        $client->addHeader('Content-Type', 'application/x-www-form-urlencoded');

        $response = $client->fetch(
            url: $this->endpoint.'/v2/oauth/access_token',
            method: FetchClient::METHOD_POST,
            body: [
                'client_id' => $this->appID,
                'client_secret' => $this->getClientSecret(),
                'code' => 'intentionally-invalid-code',
                'redirect_uri' => 'https://invalid.appwrite.callback/intentionally-invalid',
            ]
        );

        $json = \json_decode($response->getBody(), true);

        $code = $json['error']['code'] ?? $json['error'] ?? null;

        if ($code === 'invalid_client') {
            throw new \Exception('Vercel application with the provided Client ID and/or Client Secret is invalid.');
        }
    }
}
