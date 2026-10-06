<?php

namespace Appwrite\Autogravity;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Client
{
    private Factory $factory;

    public function __construct(private readonly ClientInterface $client)
    {
        $this->factory = new Factory();
    }

    /**
     * Find the image's focal point.
     *
     * @throws Exception on an error response or malformed result
     * @throws ClientExceptionInterface on transport failure
     */
    public function analyze(string $image): Gravity
    {
        $request = $this->factory->body(
            Method::POST,
            'analyze',
            $image,
            'application/octet-stream'
        );
        $response = $this->client->sendRequest($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $result = \json_decode($body, true);

        if ($status >= 400) {
            $error = \is_array($result) ? ($result['error'] ?? null) : null;
            throw new Exception(\is_string($error) ? $error : 'Autogravity failed with status ' . $status, $status);
        }

        $x = \is_array($result) ? ($result['gravity']['x'] ?? null) : null;
        $y = \is_array($result) ? ($result['gravity']['y'] ?? null) : null;
        if (!\is_numeric($x) || !\is_numeric($y)) {
            throw new Exception('Autogravity returned an invalid gravity', $status);
        }

        return new Gravity((float) $x, (float) $y);
    }
}
