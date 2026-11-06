<?php

declare(strict_types=1);

namespace Utopia\Psr7\Stream;

use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Utopia\Psr7\Stream;

final class Factory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $resource = @fopen($filename, $mode);

        if (!\is_resource($resource)) {
            throw new RuntimeException('Unable to open stream file.');
        }

        return Stream::fromResource($resource);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Stream::fromResource($resource);
    }
}
