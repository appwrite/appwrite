<?php

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
     * Return the name of compression algorithm.
     */
    abstract public function getName(): string;

    /**
     * Return the id of compression algorithm used in content-encoding and accept-encoding headers.
     */
    public function getContentEncoding(): string
    {
        return strtolower($this->getName());
    }

    /**
     * Compress data.
     *
     * @param $data
     * @return string
     */
    abstract public function compress(string $data);

    /**
     * Decompress data.
     *
     * @param $data
     * @return string
     */
    abstract public function decompress(string $data);

    /**
     * Return true if the compression algorithm is supported.
     */
    abstract public static function isSupported(): bool;

    /**
     * Create a compression algorithm from the name.
     */
    public static function fromName(string $name): ?Compression
    {
        $name = strtolower($name);

        return match ($name) {
            Compression::BROTLI, 'br' => new Algorithms\Brotli(),
            Compression::DEFLATE => new Algorithms\Deflate(),
            Compression::GZIP => new Algorithms\GZIP(),
            Compression::LZ4 => new Algorithms\LZ4(),
            Compression::SNAPPY => new Algorithms\Snappy(),
            Compression::XZ => new Algorithms\XZ(),
            Compression::ZSTD => new Algorithms\Zstd(),
            default => null,
        };
    }

    /**
     * @param  string  $acceptEncoding String in format <encoding-method1>[;q=<weight>], <encoding-method2>[;q=<weight>], ...
     *  Where:
     *      - <encoding-method> is the name of an encoding algorithm
     *      - [;q=<weight>] is an optional quality value from 0 to 1, indicating preference (1 being the highest)
     * @param  array  $supported List of supported compression algorithms, if not provided, the default list will be used
     *  The default list is [zstd, br, gzip, deflate, none, identity]
     */
    public static function fromAcceptEncoding(string $acceptEncoding, array $supported = []): ?Compression
    {
        if ($acceptEncoding === '' || $acceptEncoding === '0') {
            return null;
        }

        if ($supported === []) {
            $supported = [
                self::ZSTD => Algorithms\Zstd::isSupported(),
                self::BROTLI => Algorithms\Brotli::isSupported(),
                self::GZIP => Algorithms\GZIP::isSupported(),
                self::DEFLATE => Algorithms\Deflate::isSupported(),
                self::NONE => true,
                self::IDENTITY => true,
            ];
        } elseif (array_is_list($supported)) {
            // Convert flat array to associative array
            $supported = array_fill_keys($supported, true);
        }

        // Map encoding aliases to canonical names
        $aliases = [
            'br' => self::BROTLI,
        ];

        $encodings = array_map(trim(...), explode(',', $acceptEncoding));
        $encodings = array_map(strtolower(...), $encodings);

        $encodings = array_map(function (string $encoding) use ($aliases): array {
            $parts = explode(';', $encoding);
            $encoding = $aliases[$parts[0]] ?? $parts[0];
            $quality = 1.0;

            if (isset($parts[1])) {
                $quality = \floatval(str_replace('q=', '', $parts[1]));
            }

            return [
                'encoding' => $encoding,
                'quality' => $quality,
            ];
        }, $encodings);

        $encodings = array_filter($encodings, fn(array $encoding): bool => isset($supported[$encoding['encoding']]) && $supported[$encoding['encoding']]);

        if ($encodings === []) {
            return null;
        }

        usort($encodings, fn(array $a, array $b): int => $b['quality'] <=> $a['quality']);

        return self::fromName($encodings[0]['encoding']);
    }
}
