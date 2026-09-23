<?php

declare(strict_types=1);

namespace Utopia\Client\Response;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final readonly class Builder
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function build(
        int $statusCode,
        string $reasonPhrase,
        array $headers,
        string $body,
        string $protocolVersion = '1.1',
    ): ResponseInterface {
        $response = $this->responseFactory
            ->createResponse($statusCode, $reasonPhrase)
            ->withProtocolVersion($protocolVersion)
            ->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response;
    }
}
