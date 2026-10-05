<?php

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class XZ extends Compression
{
    public function getName(): string
    {
        return Compression::XZ;
    }

    /**
     * Compress.
     */
    public function compress(string $data): string
    {
        return xzencode($data);
    }

    /**
     * Decompress.
     */
    public function decompress(string $data): string
    {
        return xzdecode($data);
    }

    /**
     * Check if the algorithm is supported.
     */
    public static function isSupported(): bool
    {
        return \function_exists('xzencode');
    }
}
