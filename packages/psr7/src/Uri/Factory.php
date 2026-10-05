<?php

declare(strict_types=1);

namespace Utopia\Psr7\Uri;

use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Utopia\Psr7\Uri;

final class Factory implements UriFactoryInterface
{
    public function createUri(string $uri = ''): UriInterface
    {
        return Uri::parse($uri);
    }
}
