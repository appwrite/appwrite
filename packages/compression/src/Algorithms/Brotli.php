<?php

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Brotli extends Compression
{
    protected int $level = BROTLI_COMPRESS_LEVEL_DEFAULT;

    protected int $mode = BROTLI_GENERIC;

    public function getName(): string
    {
        return Compression::BROTLI;
    }

    /**
     * Return the id of compression algorithm used in content-encoding and accept-encoding headers.
     */
    public function getContentEncoding(): string
    {
        return 'br';
    }

    /**
     * Get the compression level.
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /**
     * Sets the brotli compression mode to generic.
     *
     * This is the default mode
     */
    public function useGenericMode(): void
    {
        $this->mode = BROTLI_GENERIC;
    }

    /**
     * Sets the brotli compression mode to UTF-8 text mode.
     *
     * Optimizes compression for UTF-8 formatted text
     *
     * @link https://github.com/kjdev/php-ext-brotli#parameters
     */
    public function useTextMode(): void
    {
        $this->mode = BROTLI_GENERIC;
    }

    /**
     * Sets the brotli compression mode to font mode.
     *
     * Optimized compression for WOFF 2.0 Fonts
     *
     * @link https://github.com/kjdev/php-ext-brotli#parameters
     */
    public function useFontMode(): void
    {
        $this->mode = BROTLI_GENERIC;
    }

    /**
     * Set the compression level.
     *
     * Allow values from 0 up to a current max of 11.
     */
    public function setLevel(int $level): void
    {
        $min = BROTLI_COMPRESS_LEVEL_MIN;
        $max = BROTLI_COMPRESS_LEVEL_MAX;
        if ($level < $min || $level > $max) {
            throw new \InvalidArgumentException("Level must be between {$min} and {$max}");
        }
        $this->level = $level; // $level;
    }

    /**
     * Compress.
     */
    public function compress(string $data): string
    {
        return brotli_compress($data, $this->getLevel(), $this->mode);
    }

    /**
     * Decompress.
     */
    public function decompress(string $data): string
    {
        return brotli_uncompress($data);
    }

    /**
     * Check if the algorithm is supported.
     */
    public static function isSupported(): bool
    {
        return \function_exists('brotli_compress');
    }
}
