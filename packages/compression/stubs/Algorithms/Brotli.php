<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Brotli extends Compression
{
    protected int $level = 11;

    protected int $mode = 0;

    /**
     * br
     */
    public function getContentEncoding(): string
    {
    }

    public function getLevel(): int
    {
    }

    public function useGenericMode(): void
    {
    }

    public function useTextMode(): void
    {
    }

    public function useFontMode(): void
    {
    }

    /**
     * @throws \InvalidArgumentException  outside 0 to 11
     */
    public function setLevel(int $level): void
    {
    }

    public function getName(): string
    {
    }

    public function compress(string $data): string
    {
    }

    public function decompress(string $data): string
    {
    }

    public static function isSupported(): bool
    {
    }
}
