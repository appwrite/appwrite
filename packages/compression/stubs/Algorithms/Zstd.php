<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Zstd extends Compression
{
    /**
     * @param  int  $level  1 to 22; 20 and up need much more memory
     */
    public function __construct(protected int $level = 3)
    {
    }

    public function getLevel(): int
    {
    }

    /**
     * @throws \InvalidArgumentException  outside 1 to 22
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
