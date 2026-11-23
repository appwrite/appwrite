<?php

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Deflate extends Compression
{
    private int $level = 6;

    public function getName(): string
    {
        return Compression::DEFLATE;
    }

    /**
     * Compress.
     */
    public function compress(string $data): string
    {
        return gzdeflate($data, $this->level);
    }

    /**
     * Decompress.
     */
    public function decompress(string $data): string
    {
        return gzinflate($data);
    }

    /**
     * Check if the algorithm is supported.
     */
    public static function isSupported(): bool
    {
        return \function_exists('gzdeflate');
    }
}
