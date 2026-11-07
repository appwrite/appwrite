<?php

namespace Appwrite\Storage;

use Appwrite\Auth\EncryptionKey;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\Compression\Compression;
use Utopia\Database\Document;
use Utopia\Psr7\Stream;
use Utopia\Storage\Cipher;
use Utopia\Storage\Device;
use Utopia\Storage\Exception\StorageException;

/**
 * How a stored file is encrypted. Files written before segmented encryption
 * keep one AES-128-GCM envelope over the whole file. Segmented files have
 * their own random key, wrapped by `_APP_OPENSSL_KEY_V*` and kept in the
 * file's internal metadata.
 */
final readonly class Encryption
{
    private const string WRAP = 'aes-256-gcm';

    public function __construct(private Document $file)
    {
    }

    /**
     * A new file key, wrapped with the current key, as stored in file metadata.
     *
     * @return array{key: string, version: string}
     */
    public static function create(): array
    {
        $master = EncryptionKey::current();
        if ($master === '') {
            throw new StorageException('Encryption key is not configured. Please configure _APP_OPENSSL_KEY_V' . EncryptionKey::version() . ' in .env file.');
        }

        $nonce = \random_bytes(12);
        $tag = '';
        $wrapped = \openssl_encrypt(\random_bytes(32), self::WRAP, \hash('sha256', $master, true), OPENSSL_RAW_DATA, $nonce, $tag, Cipher::NAME, 16);
        if ($wrapped === false) {
            throw new StorageException('Failed to wrap file key');
        }

        return [
            'key' => \base64_encode($nonce . $wrapped . $tag),
            'version' => EncryptionKey::version(),
        ];
    }

    /**
     * @param array{key: string, version: string} $envelope
     */
    public static function cipher(array $envelope): Cipher
    {
        $data = \base64_decode($envelope['key'], true);
        $master = EncryptionKey::get($envelope['version']);
        $key = $data === false || $master === '' ? false : \openssl_decrypt(
            \substr($data, 12, -16),
            self::WRAP,
            \hash('sha256', $master, true),
            OPENSSL_RAW_DATA,
            \substr($data, 0, 12),
            \substr($data, -16),
            Cipher::NAME
        );

        if ($key === false) {
            throw new StorageException('Unable to unwrap the file key with _APP_OPENSSL_KEY_V' . $envelope['version']);
        }

        return new Cipher($key);
    }

    /**
     * True for segmented files stored without compression, which can be read by range.
     */
    public function isSegmented(): bool
    {
        return $this->file->getAttribute('openSSLCipher') === Cipher::NAME
            && $this->file->getAttribute('algorithm', Compression::NONE) === Compression::NONE;
    }

    /**
     * Decrypt the whole stored file, in either format.
     */
    public function decrypt(string $data): string
    {
        $cipher = $this->file->getAttribute('openSSLCipher');

        if ($cipher === Cipher::NAME) {
            return $this->open()->decrypt($data, 0, true);
        }

        $decrypted = OpenSSL::decrypt(
            $data,
            $cipher,
            EncryptionKey::get((string) $this->file->getAttribute('openSSLVersion')),
            0,
            \hex2bin($this->file->getAttribute('openSSLIV')),
            \hex2bin($this->file->getAttribute('openSSLTag'))
        );

        if ($decrypted === false) {
            throw new StorageException('Failed to decrypt file ' . $this->file->getId());
        }

        return $decrypted;
    }

    /**
     * Plaintext bytes $start to $end of a segmented file, reading only the segments they fall in.
     */
    public function range(Device $device, int $start, int $end): string
    {
        $first = \intdiv($start, Cipher::SEGMENT);
        $last = \intdiv($end, Cipher::SEGMENT);
        $offset = Cipher::offset($first);
        $data = (string) $device->read($this->path(), $offset, Cipher::offset($last + 1) - $offset);
        $final = $last === Cipher::segments((int) $this->file->getAttribute('sizeOriginal', 0)) - 1;

        return \substr($this->open()->decrypt($data, $first, $final), $start - $first * Cipher::SEGMENT, $end - $start + 1);
    }

    /**
     * Stream a segmented file to $sink as plaintext, one backend read and one segment in memory at a time.
     *
     * @param callable(string): void $sink
     */
    public function stream(Device $device, callable $sink): void
    {
        $cipher = $this->open();
        $size = Cipher::SEGMENT + Cipher::OVERHEAD;
        $total = Cipher::segments((int) $this->file->getAttribute('sizeOriginal', 0));
        $buffer = '';
        $index = 0;

        $device->stream($this->path(), static function (string $chunk) use ($cipher, $size, $total, $sink, &$buffer, &$index): void {
            $buffer .= $chunk;
            // The final segment waits for the end of the file, where it is checked as the last one.
            $count = \min(\intdiv(\strlen($buffer), $size), $total - 1 - $index);
            if ($count <= 0) {
                return;
            }

            $sink($cipher->decrypt(\substr($buffer, 0, $count * $size), $index, false));
            $buffer = \substr($buffer, $count * $size);
            $index += $count;
        });

        if ($index !== $total - 1) {
            throw new StorageException('Incomplete file read');
        }

        $sink($cipher->decrypt($buffer, $index, true));
    }

    /**
     * Copy a segmented file to $target as plaintext, in chunks so memory stays bounded.
     */
    public function copy(Device $from, Device $to, string $target, string $contentType): void
    {
        $size = (int) $this->file->getAttribute('sizeOriginal', 0);
        $chunks = \max(1, (int) \ceil($size / APP_LIMIT_UPLOAD_CHUNK_SIZE));
        $metadata = ['content_type' => $contentType];

        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $start = $chunk * APP_LIMIT_UPLOAD_CHUNK_SIZE;
            $end = \min($size, $start + APP_LIMIT_UPLOAD_CHUNK_SIZE) - 1;
            $data = $size === 0 ? '' : $this->range($from, $start, $end);
            $to->upload(new Stream($data), $target, $contentType, $chunk + 1, $chunks, $metadata);
        }
    }

    private function open(): Cipher
    {
        return self::cipher($this->file->getAttribute('metadata', [])['encryption'] ?? throw new StorageException('File ' . $this->file->getId() . ' has no key'));
    }

    private function path(): string
    {
        return $this->file->getAttribute('path', '');
    }
}
