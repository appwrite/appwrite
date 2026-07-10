<?php

namespace Appwrite\Platform\Modules\Functions\Workers\Screenshots;

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
        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            throw new \Exception((string) $response->getBody());
        }

        return (string) $response->getBody();
    }
}
