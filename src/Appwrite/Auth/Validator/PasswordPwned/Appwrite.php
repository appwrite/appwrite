<?php

namespace Appwrite\Auth\Validator\PasswordPwned;

use Appwrite\Auth\Validator\PasswordPwned;
use Appwrite\Extend\Exception;
use Psr\Http\Client\ClientInterface;
use Utopia\Cache\Cache;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Client;
use Utopia\DSN\DSN;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Asks an Appwrite Pwned service, https://github.com/appwrite-labs/pwned.
 *
 * The service answers from its own copy of the Have I Been Pwned corpus, so
 * unlike `HIBP` no third party is asked and nothing about the password leaves
 * your network. The password is hashed here and only its SHA-1 travels, whole
 * rather than as a prefix. The request carries a secret the service shares as
 * a Bearer token, and the hash that travels is unsalted, so only point this at
 * a service on a network you control or behind TLS.
 *
 * DSN: `appwrite://SECRET@HOST[:PORT][/PATH][?tls=true]`. The secret must match
 * the service's `APPWRITE_PWNED_SECRET`, the path defaults to `v1/detection`,
 * and the connection is plain HTTP unless `tls=true`.
 */
class Appwrite extends PasswordPwned
{
    private const PATH = 'v1/detection';
    private const CONNECT_TIMEOUT = 3; // seconds
    private const REQUEST_TIMEOUT = 5; // seconds

    protected string $endpoint;
    protected string $secret;
    protected ClientInterface $client;

    public function __construct(DSN $dsn, ?Cache $cache = null, ?ClientInterface $client = null, bool $allowEmpty = false)
    {
        parent::__construct($allowEmpty);

        $this->cache = $cache;
        $scheme = $dsn->getParam('tls') === 'true' ? 'https' : 'http';
        $port = $dsn->getPort() !== null ? ':' . $dsn->getPort() : '';
        $path = $dsn->getPath();

        $this->endpoint = $scheme . '://' . $dsn->getHost() . $port . '/' . ($path === '' || $path === null ? self::PATH : $path);
        $this->secret = $dsn->getUser() ?? '';
        $this->client = $client ?? (new Client(new CurlAdapter()))
            ->withConnectTimeout(self::CONNECT_TIMEOUT)
            ->withTimeout(self::REQUEST_TIMEOUT)
            ->withHeaders([Header::USER_AGENT => 'Appwrite']);
    }

    protected function isPwned(string $password): bool
    {
        // The service takes the SHA-1 hash of the password, never the password itself
        $hash = \strtoupper(\sha1($password));

        // Keyed with the secret the service shares, so the cache never holds a crackable password hash
        $key = 'pwned-passwords:' . \md5($this->endpoint) . ':' . \hash_hmac('sha256', $hash, $this->secret);

        $answer = $this->remember($key, function () use ($hash) {
            try {
                $response = $this->client->sendRequest((new RequestFactory())->body(
                    Method::POST,
                    $this->endpoint,
                    \json_encode(['hash' => $hash], JSON_THROW_ON_ERROR),
                    ContentType::JSON,
                    [Header::AUTHORIZATION => 'Bearer ' . $this->secret],
                ));
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
            }

            // Anything else is the service's own error object: a rejected secret
            // (401), a hash it will not take (400) or a missing dataset (503)
            if ($response->getStatusCode() !== 200) {
                throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
            }

            $body = \json_decode((string) $response->getBody(), true);

            if (!\is_array($body) || !\is_bool($body['leaked'] ?? null)) {
                throw new Exception(Exception::GENERAL_PWNED_PASSWORDS_UNAVAILABLE);
            }

            return ['leaked' => $body['leaked']];
        });

        return (bool) ($answer['leaked'] ?? false);
    }
}
