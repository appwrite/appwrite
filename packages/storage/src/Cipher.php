<?php

declare(strict_types=1);

namespace Utopia\Storage;

use Utopia\Psr7\Stream;
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
     * A cipher with a new random key.
     */
    public static function generate(): self
    {
        return new self(\random_bytes(32));
    }

    /**
     * Restore a cipher from a key wrapped with $master by wrap().
     */
    public static function unwrap(string $wrapped, string $master): self
    {
        $key = \strlen($wrapped) !== self::NONCE + 32 + self::TAG ? false : \openssl_decrypt(
            \substr($wrapped, self::NONCE, -self::TAG),
            self::ALGORITHM,
            self::master($master),
            OPENSSL_RAW_DATA,
            \substr($wrapped, 0, self::NONCE),
            \substr($wrapped, -self::TAG),
            self::NAME
        );

        if ($key === false) {
            throw new StorageException('Unable to unwrap the key');
        }

        return new self($key);
    }

    /**
     * This cipher's key, encrypted with $master, to store beside the file.
     */
    public function wrap(string $master): string
    {
        $nonce = \random_bytes(self::NONCE);
        $tag = '';
        $wrapped = \openssl_encrypt($this->key, self::ALGORITHM, self::master($master), OPENSSL_RAW_DATA, $nonce, $tag, self::NAME, self::TAG);

        if ($wrapped === false) {
            throw new StorageException('Failed to wrap the key');
        }

        return $nonce . $wrapped . $tag;
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
     * Plaintext bytes $start to $end of the file at $path, which holds $size
     * bytes of plaintext. Reads only the segments the range falls in.
     */
    public function range(Device $device, string $path, int $size, int $start, int $end): string
    {
        $first = \intdiv($start, self::SEGMENT);
        $last = \intdiv($end, self::SEGMENT);
        $offset = self::offset($first);
        $length = self::offset($last - $first + 1);
        if ($offset < 0 || $length <= 0) {
            throw new \InvalidArgumentException('Invalid range ' . $start . '-' . $end);
        }

        $data = (string) $device->read($path, $offset, $length);
        $final = $last === self::segments($size) - 1;

        return \substr($this->decrypt($data, $first, $final), $start - $first * self::SEGMENT, $end - $start + 1);
    }

    /**
     * Send the file at $path, which holds $size bytes of plaintext, to $sink
     * as plaintext. Keeps one device read and one segment in memory at a time.
     *
     * @param callable(string): void $sink
     */
    public function stream(Device $device, string $path, int $size, callable $sink): void
    {
        $length = self::SEGMENT + self::OVERHEAD;
        $total = self::segments($size);
        $buffer = '';
        $index = 0;

        $device->stream($path, function (string $chunk) use ($length, $total, $sink, &$buffer, &$index): void {
            $buffer .= $chunk;
            // The final segment waits for the end of the file, where it is checked as the last one.
            $count = \min(\intdiv(\strlen($buffer), $length), $total - 1 - $index);
            if ($count <= 0) {
                return;
            }

            $sink($this->decrypt(\substr($buffer, 0, $count * $length), $index, false));
            $buffer = \substr($buffer, $count * $length);
            $index += $count;
        });

        if ($index !== $total - 1) {
            throw new StorageException('Incomplete file read');
        }

        $sink($this->decrypt($buffer, $index, true));
    }

    /**
     * Copy the file at $path, which holds $size bytes of plaintext, to
     * $target on $to as plaintext, uploading $chunk bytes at a time.
     */
    public function copy(Device $from, string $path, int $size, Device $to, string $target, string $contentType, int $chunk): void
    {
        $chunks = \max(1, \intdiv($size + $chunk - 1, $chunk));
        $metadata = ['content_type' => $contentType];

        for ($index = 0; $index < $chunks; $index++) {
            $start = $index * $chunk;
            $end = \min($size, $start + $chunk) - 1;
            $data = $size === 0 ? '' : $this->range($from, $path, $size, $start, $end);
            $to->upload(new Stream($data), $target, $contentType, $index + 1, $chunks, $metadata);
        }
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

    private static function master(string $master): string
    {
        if ($master === '') {
            throw new \InvalidArgumentException('Master key must not be empty');
        }

        return \hash('sha256', $master, true);
    }
}
