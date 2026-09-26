<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

use Utopia\Fetch\Adapter;
use Utopia\Fetch\Options\Request as RequestOptions;
use Utopia\Fetch\Response;

/**
 * Fetch adapter that records each outbound request instead of sending it,
 * answering like the installations endpoint (204) or failing on demand.
 */
final class Recorder implements Adapter
{
    /** @var array<int, array{url: string, method: string, body: mixed, headers: array<string, string>, options: RequestOptions}> */
    public array $requests = [];

    public function __construct(
        private ?\Throwable $failure = null,
    ) {
    }

    public function send(
        string $url,
        string $method,
        mixed $body,
        array $headers,
        RequestOptions $options,
        ?callable $chunkCallback = null
    ): Response {
        $this->requests[] = [
            'url' => $url,
            'method' => $method,
            'body' => $body,
            'headers' => $headers,
            'options' => $options,
        ];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new Response(204, '', []);
    }
}
