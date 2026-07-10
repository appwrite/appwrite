<?php

namespace Appwrite\Screenshots;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Client
{
    private Factory $factory;

    /**
     * @param ClientInterface $client Configured with the browser service base URI.
     */
    public function __construct(private readonly ClientInterface $client)
    {
        $this->factory = new Factory();
    }

    /**
     * Capture a screenshot of a page and return the PNG bytes.
     *
     * @param array<string, string> $headers
     * @throws \Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function create(string $url, string $theme, array $headers = [], int $sleep = 3000): string
    {
        $response = $this->client->sendRequest($this->factory->json(Method::POST, 'screenshots', [
            'url' => $url,
            'theme' => $theme,
            'headers' => $headers,
            'sleep' => $sleep,
        ]));

        if ($response->getStatusCode() >= 400) {
            throw new \Exception((string)$response->getBody());
        }

        return (string)$response->getBody();
    }
}
