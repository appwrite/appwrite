<?php

declare(strict_types=1);

namespace Utopia\Storage;

use Utopia\Storage\Exception\StorageException;

/**
 * AES-256-GCM over fixed 64 KiB segments, so a file can be encrypted in parts
 * and any range read back without the rest. Each segment is stored as
 * nonce | ciphertext | tag, and authenticated with its index and whether it
 * is the last one, so segments cannot be swapped, reordered or cut off.
 * @see \Utopia\Storage\Tests\CipherTest
 */
final readonly class Cipher
{
    public const string NAME = 'aes-256-gcm-segmented';

    public const int SEGMENT = 65536;

    public const int OVERHEAD = self::NONCE + self::TAG;

    private const string ALGORITHM = 'aes-256-gcm';

    private const int NONCE = 12;

    private const int TAG = 16;

    private const int VERSION = 1;

    public function __construct(private string $key)
    {
        if (\strlen($key) !== 32) {
            throw new \InvalidArgumentException('Key must be 32 bytes');
        }
    }

    /**
     * Encrypt data that starts at segment $index. $last marks its final
     * segment as the end of the file.
     */
    public function encrypt(string $data, int $index, bool $last): string
    {
        $length = \strlen($data);
        $count = self::segments($length);
        $output = '';

        for ($segment = 0; $segment < $count; $segment++) {
            $nonce = \random_bytes(self::NONCE);
            $tag = '';
            $encrypted = \openssl_encrypt(
                \substr($data, $segment * self::SEGMENT, self::SEGMENT),
                self::ALGORITHM,
                $this->key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $this->aad($index + $segment, $last && $segment === $count - 1),
                self::TAG
            );

            if ($encrypted === false) {
                throw new StorageException('Failed to encrypt segment ' . ($index + $segment));
            }

            $output .= $nonce . $encrypted . $tag;
        }

        return $output;
    }

    /**
     * Decrypt whole segments that start at segment $index. $last says the
     * data ends with the final segment of the file.
     */
    public function decrypt(string $data, int $index, bool $last): string
    {
        $size = self::SEGMENT + self::OVERHEAD;
        $length = \strlen($data);
        $count = \max(1, \intdiv($length + $size - 1, $size));
        $output = '';

        for ($segment = 0; $segment < $count; $segment++) {
            $block = \substr($data, $segment * $size, $size);
            $final = $segment === $count - 1;

            if (\strlen($block) < self::OVERHEAD || ((!$final || !$last) && \strlen($block) !== $size)) {
                throw new StorageException('Segment ' . ($index + $segment) . ' is truncated');
            }

            $decrypted = \openssl_decrypt(
                \substr($block, self::NONCE, -self::TAG),
                self::ALGORITHM,
                $this->key,
                OPENSSL_RAW_DATA,
                \substr($block, 0, self::NONCE),
                \substr($block, -self::TAG),
                $this->aad($index + $segment, $last && $final)
            );

            if ($decrypted === false) {
                throw new StorageException('Segment ' . ($index + $segment) . ' failed authentication');
            }

            $output .= $decrypted;
        }

        return $output;
    }

    /**
     * Number of segments for $size bytes of plaintext. An empty file is one empty segment.
     */
    public static function segments(int $size): int
    {
        return \max(1, \intdiv($size + self::SEGMENT - 1, self::SEGMENT));
    }

    /**
     * Encrypted size of $size bytes of plaintext.
     */
    public static function size(int $size): int
    {
        return $size + self::segments($size) * self::OVERHEAD;
    }

    /**
     * Encrypted offset of segment $index.
     */
    public static function offset(int $index): int
    {
        return $index * (self::SEGMENT + self::OVERHEAD);
    }

    private function aad(int $index, bool $last): string
    {
        return \pack('CJC', self::VERSION, $index, $last ? 1 : 0);
    }
}
