<?php

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Zstd extends Compression
{
    public function __construct(
        /**
         * Compression level from 1 up to a current max of 22.
         * Levels >= 20 should be used with caution, as they require more memory.
         *
         * Default value is 3.
         */
        protected int $level = 3,
    ) {}

    /**
     * Get the compression level.
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /**
     * Set the compression level.
     *
     * Allow values from 1 up to a current max of 22.
     */
    public function setLevel(int $level): void
    {
        if ($level < 1 || $level > 22) {
            throw new \InvalidArgumentException('Level must be between 1 and 22');
        }
        $this->level = $level;
    }

    /**
     * Get the name of the algorithm.
     */
    public function getName(): string
    {
        return Compression::ZSTD;
    }

    /**
     * Compress.
     */
    public function compress(string $data): string
    {
        return zstd_compress($data, $this->level);
    }

    /**
     * Decompress.
     */
    public function decompress(string $data): string
    {
        return zstd_uncompress($data);
    }

    /**
     * Check if the algorithm is supported.
     */
    public static function isSupported(): bool
    {
        return \function_exists('zstd_compress');
    }
}
