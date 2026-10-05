<?php

namespace Appwrite\Screenshots;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory;

class Client
{
    public const VIEWPORT_WIDTH = 1280;

    public const VIEWPORT_HEIGHT = 720;

    /**
     * Captured pixels per CSS pixel. Above 1 the page is rendered at a higher
     * resolution than the viewport, so previews stay sharp when the console
     * displays them on high-density screens or scales them up.
     */
    public const SCALE = 1.5;

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
     * @throws Exception on an error response
     * @throws ClientExceptionInterface on transport failure
     */
    public function create(string $url, string $theme, array $headers = [], int $sleep = 3000): string
    {
        $response = $this->client->sendRequest($this->factory->json(Method::POST, 'screenshots', [
            'url' => $url,
            'theme' => $theme,
            'headers' => $headers,
            'sleep' => $sleep,
            'timeout' => 60000,
            'viewport' => [
                'width' => self::VIEWPORT_WIDTH,
                'height' => self::VIEWPORT_HEIGHT,
            ],
            'deviceScaleFactor' => self::SCALE,
        ]));

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($status >= 400) {
            $error = \json_decode($body, true)['error'] ?? null;
            throw new Exception(\is_string($error) ? $error : 'Screenshot failed with status ' . $status, $status);
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!\str_starts_with($contentType, 'image/')) {
            throw new Exception('Expected an image response, got ' . ($contentType === '' ? 'no content type' : $contentType), $status);
        }

        return $body;
    }
}
