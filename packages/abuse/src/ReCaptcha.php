<?php

namespace Utopia\Abuse;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Client\Adapter\Curl\Client as CurlClient;
use Utopia\Client\Client;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

/**
 * @see https://developers.google.com/recaptcha/docs/verify
 */
final readonly class ReCaptcha
{
    public const string URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function __construct(
        private string $secret,
        private ClientInterface $client = new Client(new CurlClient()),
    ) {
    }

    /**
     * Returns true when Google verifies the token as human with at least the given score.
     *
     * @throws ClientExceptionInterface
     */
    public function verify(string $response, string $ip, float $score = 0.5): bool
    {
        $request = new Factory()->form(Method::POST, self::URL, [
            'secret' => $this->secret,
            'response' => $response,
            'remoteip' => $ip,
        ]);

        $body = \json_decode((string) $this->client->sendRequest($request)->getBody(), true);

        if (!\is_array($body) || ($body['success'] ?? false) !== true) {
            return false;
        }

        $actual = $body['score'] ?? null;

        return (\is_int($actual) || \is_float($actual)) && $actual >= $score;
    }
}
