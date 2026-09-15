<?php

namespace Appwrite\Auth\Pwned;

use Ahc\Jwt\JWT;
use Appwrite\Auth\Pwned;
use Utopia\Fetch\Client;

/**
 * Talks to an Appwrite Pwned service, https://github.com/appwrite-labs/pwned.
 *
 * The service answers for a whole password rather than a hash prefix, so unlike
 * `HIBP` this sends the password itself. It travels inside a short-lived JWT
 * signed with a secret shared with the service, which is only safe on a network
 * you control. In exchange the service caches answers and can front Have I Been
 * Pwned or another detector without the server knowing which.
 */
class Appwrite extends Pwned
{
    public const ENDPOINT = 'http://appwrite-pwned/v1/detection';

    // The service decodes with the same window
    private const TOKEN_EXPIRY = 900; // seconds
    private const TOKEN_LEEWAY = 10; // seconds

    protected string $secret;

    public function __construct(string $endpoint = '', string $secret = '', ?Client $client = null)
    {
        parent::__construct($endpoint ?: self::ENDPOINT, $client);

        $this->secret = $secret;
    }

    public function getName(): string
    {
        return 'appwrite';
    }

    public function isPwned(string $password): bool
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
