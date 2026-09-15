<?php

namespace Appwrite\Auth\Validator\PasswordPwned;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Validator\PasswordPwned;
use Utopia\DSN\DSN;
use Utopia\Fetch\Client;

/**
 * Asks an Appwrite Pwned service, https://github.com/appwrite-labs/pwned.
 *
 * The service answers for a whole password rather than a hash prefix, so unlike
 * `HIBP` this sends the password itself. It travels inside a short-lived JWT
 * signed with a secret the service shares, which is only safe on a network you
 * control. In exchange the service caches answers and can front Have I Been
 * Pwned or another detector without this server knowing which.
 *
 * DSN: `appwrite://SECRET@HOST[:PORT][/PATH][?tls=true]`. The secret must match
 * the service's `APPWRITE_PWNED_JWT_SECRET`, the path defaults to
 * `v1/detection`, and the connection is plain HTTP unless `tls=true`.
 */
class Appwrite extends PasswordPwned
{
    private const PATH = 'v1/detection';

    // The service decodes with the same window
    private const TOKEN_EXPIRY = 900; // seconds
    private const TOKEN_LEEWAY = 10; // seconds

    protected string $endpoint;
    protected string $secret;
    protected Client $client;

    /**
     * @param array<string, mixed> $policy
     */
    public function __construct(array $policy, DSN $dsn, ?Client $client = null)
    {
        parent::__construct($policy);

        $scheme = $dsn->getParam('tls') === 'true' ? 'https' : 'http';
        $port = $dsn->getPort() !== null ? ':' . $dsn->getPort() : '';
        $path = $dsn->getPath();

        $this->endpoint = $scheme . '://' . $dsn->getHost() . $port . '/' . ($path === '' || $path === null ? self::PATH : $path);
        $this->secret = $dsn->getUser() ?? '';
        $this->client = $this->client($client);
    }

    protected function isPwned(string $password): bool
    {
        $jwt = new JWT($this->secret, 'HS256', self::TOKEN_EXPIRY, self::TOKEN_LEEWAY);

        try {
            $response = $this->client
                ->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON)
                ->fetch($this->endpoint, Client::METHOD_POST, [
                    'password' => $jwt->encode(['password' => $password]),
                ]);
        } catch (\Throwable) {
            $this->unavailable();
        }

        if ($response->getStatusCode() !== 200) {
            $this->unavailable();
        }

        $body = \json_decode($response->text(), true);

        if (!\is_array($body) || !\is_bool($body['leaked'] ?? null)) {
            $this->unavailable();
        }

        return $body['leaked'];
    }
}
