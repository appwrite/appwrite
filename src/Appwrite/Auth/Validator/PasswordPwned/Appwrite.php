<?php

namespace Appwrite\Auth\Validator\PasswordPwned;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Extend\Exception;
use Utopia\Cache\Cache;
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
    private const CONNECT_TIMEOUT = 3 * 1000; // milliseconds
    private const REQUEST_TIMEOUT = 5 * 1000; // milliseconds

    // The service decodes with the same window
    private const TOKEN_EXPIRY = 900; // seconds
    private const TOKEN_LEEWAY = 10; // seconds

    protected string $endpoint;
    protected string $secret;
    protected Client $client;

    public function __construct(DSN $dsn, ?Cache $cache = null, ?Client $client = null)
    {
        $this->cache = $cache;
        $scheme = $dsn->getParam('tls') === 'true' ? 'https' : 'http';
        $port = $dsn->getPort() !== null ? ':' . $dsn->getPort() : '';
        $path = $dsn->getPath();

        $this->endpoint = $scheme . '://' . $dsn->getHost() . $port . '/' . ($path === '' || $path === null ? self::PATH : $path);
        $this->secret = $dsn->getUser() ?? '';
        $this->client = $client ?? (new Client())
            ->setConnectTimeout(self::CONNECT_TIMEOUT)
            ->setTimeout(self::REQUEST_TIMEOUT)
            ->setAllowRedirects(false)
            ->setUserAgent('Appwrite');
    }

    protected function isPwned(string $password): bool
    {
        // Keyed with the secret the service shares, so the cache never holds a crackable password hash
        $key = 'pwned-passwords:' . \md5($this->endpoint) . ':' . \hash_hmac('sha256', $password, $this->secret);

        $answer = $this->remember($key, function () use ($password) {
            $jwt = new JWT($this->secret, 'HS256', self::TOKEN_EXPIRY, self::TOKEN_LEEWAY);

            try {
                $response = $this->client
                    ->addHeader('content-type', Client::CONTENT_TYPE_APPLICATION_JSON)
                    ->fetch($this->endpoint, Client::METHOD_POST, [
                        'password' => $jwt->encode(['password' => $password]),
                    ]);
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
            }

            if ($response->getStatusCode() !== 200) {
                throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
            }

            $body = \json_decode($response->text(), true);

            if (!\is_array($body) || !\is_bool($body['leaked'] ?? null)) {
                throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
            }

            return ['leaked' => $body['leaked']];
        });

        return (bool) ($answer['leaked'] ?? false);
    }
}
