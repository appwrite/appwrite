<?php

namespace Appwrite\Platform\Modules\Functions\Workers\Screenshots;

use Appwrite\AppwriteException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request;

final readonly class Client
{
    public function __construct(
        private ClientInterface $client,
        private string $uri,
        private Request\Factory $requests = new Request\Factory(),
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function capture(array $config): string
    {
        $request = $this->requests->json(Method::POST, $this->uri, $config);
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw new AppwriteException($error->getMessage());
        }

        if ($response->getStatusCode() >= 400) {
            $body = (string) $response->getBody();
            $error = \json_decode($body, true);

            if (\is_array($error)) {
                throw new AppwriteException(
                    \is_string($error['message'] ?? null) ? $error['message'] : $body,
                    $response->getStatusCode(),
                    \is_string($error['type'] ?? null) ? $error['type'] : '',
                    $body,
                );
            }

            throw new AppwriteException($body, $response->getStatusCode(), response: $body);
        }

        return (string) $response->getBody();
    }
}
