<?php

namespace Appwrite\Exiftool;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Client
{
    private Factory $factory;

    public function __construct(private readonly ?ClientInterface $client)
    {
        $this->factory = new Factory();
    }

    /**
     * Remove metadata such as location, camera, and capture time from an image.
     *
     * @throws Exception when stripping is not configured, on an error response, or on an empty file
     * @throws ClientExceptionInterface on transport failure
     */
    public function strip(string $image): string
    {
        if ($this->client === null) {
            throw new Exception('Exiftool needs to be configured with _APP_EXIFTOOL_HOST to strip metadata');
        }

        $request = $this->factory->body(
            Method::POST,
            'strip',
            $image,
            'application/octet-stream'
        );
        $response = $this->client->sendRequest($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            $result = \json_decode($body, true);
            $error = \is_array($result) ? ($result['error'] ?? null) : null;
            throw new Exception(\is_string($error) ? $error : 'Exiftool failed with status ' . $status, $status);
        }

        if ($body === '') {
            throw new Exception('Exiftool returned an empty file', $status);
        }

        return $body;
    }
}
