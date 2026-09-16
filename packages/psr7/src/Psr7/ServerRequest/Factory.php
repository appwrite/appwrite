<?php

declare(strict_types=1);

namespace Utopia\Psr7\ServerRequest;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Psr7\ServerRequest;
use Utopia\Psr7\Uri;

final readonly class Factory implements ServerRequestFactoryInterface
{
    public function __construct(
        private UriFactoryInterface $uriFactory = new Uri\Factory(),
    ) {}

    /**
     * @param array<string, mixed> $serverParams
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        $uri = $uri instanceof UriInterface ? $uri : $this->uriFactory->createUri((string) $uri);

        return new ServerRequest(strtoupper($method), $uri, $serverParams)
            ->withUri($uri);
    }
}
