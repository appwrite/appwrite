<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class LZ4 extends Compression
{
    /**
     * @param  int  $level  0 (not high compression) to 12
     */
    public function __construct(protected int $level = 0)
    {
    }

    public function getLevel(): int
    {
    }

    /**
     * @throws \InvalidArgumentException  outside 0 to 12
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
