<?php

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Snappy extends Compression
{
    public function getName(): string
    {
        return Compression::SNAPPY;
    }

    /**
     * Compress.
     */
    public function compress(string $data): string
    {
        return snappy_compress($data);
    }

    /**
     * Decompress.
     */
    public function decompress(string $data): string
    {
        return snappy_uncompress($data);
    }

    /**
     * Check if the algorithm is supported.
     */
    public static function isSupported(): bool
    {
        return \function_exists('snappy_compress');
    }
}
