<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Compression;

abstract class Compression
{
    public const NONE = 'none';

    /**
     * @deprecated Use Compression::NONE instead.
     */
    public const IDENTITY = 'identity';

    public const BROTLI = 'brotli';

    public const DEFLATE = 'deflate';

    public const GZIP = 'gzip';

    public const LZ4 = 'lz4';

    public const SNAPPY = 'snappy';

    public const XZ = 'xz';

    public const ZSTD = 'zstd';

    /**
     * The name of the algorithm.
     */
    abstract public function getName(): string;

    /**
     * The algorithm's id in Content-Encoding and Accept-Encoding headers.
     */
    public function getContentEncoding(): string
    {
    }

    /**
     * @return string
     */
    abstract public function compress(string $data);

    /**
     * @return string
     */
    abstract public function decompress(string $data);

    /**
     * Whether the algorithm can run in this process.
     */
    abstract public static function isSupported(): bool;

    /**
     * The algorithm named, or null for an unknown name.
     */
    public static function fromName(string $name): ?Compression
    {
    }

    /**
     * The algorithm the header prefers most among those supported, or null for none.
     *
     * @param  string  $acceptEncoding  <encoding>[;q=<weight>], <encoding>[;q=<weight>], ...
     * @param  array<mixed>  $supported  names, or names to whether each is supported; empty for zstd, br, gzip, deflate, none and identity
     */
    public static function fromAcceptEncoding(string $acceptEncoding, array $supported = []): ?Compression
    {
    }
}
