<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Compression\Algorithms;

use Utopia\Compression\Compression;

class Deflate extends Compression
{
    private int $level = 6;

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
