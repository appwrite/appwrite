<?php

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class LZ4 extends Compression
{
    public function __construct(
        /**
         * Compression level from 0 up to a current max of 12.
         * Recommended values are between 4 and 9.
         *
         * Default value is 0, Not high compression mode.
         */
        protected int $level = 0,
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
     * Allow values from 0 up to a current max of 12.
     */
    public function setLevel(int $level): void
    {
        if ($level < 0 || $level > 12) {
            throw new \InvalidArgumentException('Level must be between 0 and 12');
        }
        $this->level = $level;
    }

    /**
     * Get the name of the algorithm.
     */
    public function getName(): string
    {
        return Compression::LZ4;
    }

    /**
     * Compress.
     */
    public function compress(string $data): string
    {
        return lz4_compress($data, $this->level);
    }

    /**
     * Decompress.
     */
    public function decompress(string $data): string
    {
        return lz4_uncompress($data);
    }

    /**
     * Check if the algorithm is supported.
     */
    public static function isSupported(): bool
    {
        return \function_exists('lz4_compress');
    }
}
