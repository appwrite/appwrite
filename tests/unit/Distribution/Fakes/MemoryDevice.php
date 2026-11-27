<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Psr\Http\Message\StreamInterface;
use Utopia\Storage\Device\S3;

/**
 * An S3 device that keeps its objects in memory, shared by every instance until reset.
 */
final class MemoryDevice extends S3
{
    /** @var array<string, string> Path => content */
    public static array $objects = [];

    public function __construct(string $root)
    {
        parent::__construct($root, 'access', 'secret', 'https://s3.example.test', 'us-east-1');
    }

    public static function reset(): void
    {
        self::$objects = [];
    }

    #[\Override]
    public function write(string $path, StreamInterface $data, string $contentType = ''): string
    {
        $data->rewind();
        self::$objects[$path] = $data->getContents();

        return \md5(self::$objects[$path]);
    }

    #[\Override]
    public function exists(string $path): bool
    {
        return isset(self::$objects[$path]);
    }

    #[\Override]
    public function delete(string $path, bool $recursive = false): bool
    {
        unset(self::$objects[$path]);

        return true;
    }
}
