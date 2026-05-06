<?php

namespace Tests\Unit\Utopia\Messaging\Adapter;

use Appwrite\Utopia\Messaging\Adapter\Webhook;

/**
 * Test double that captures the curl request the adapter would issue and
 * returns a scripted response, so callers exercise the real signing/header
 * logic without touching the network. Lives in its own file so PSR-4
 * autoload resolves it from any test file.
 */
class CapturingWebhook extends Webhook
{
    /**
     * @var array<int, array{method: string, url: string, headers: array<int, string>, body: string, timeout: int}>
     */
    public array $captured = [];

    /** @var array{statusCode: int, response: string|null, error: string|null} */
    public array $response = ['statusCode' => 200, 'response' => 'OK', 'error' => null];

    protected function dispatch(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $this->captured[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
        ];
        return $this->response;
    }
}
