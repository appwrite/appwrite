<?php

namespace Appwrite\Storage;

use Appwrite\Auth\EncryptionKey;
use Appwrite\OpenSSL\OpenSSL;
use Utopia\Compression\Compression;
use Utopia\Database\Document;
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

        return [
            'key' => \base64_encode(Cipher::generate()->wrap($master)),
            'version' => EncryptionKey::version(),
        ];
    }

    /**
     * @param array{key: string, version: string} $envelope
     */
    public static function cipher(array $envelope): Cipher
    {
        $wrapped = \base64_decode($envelope['key'], true);
        $master = EncryptionKey::get($envelope['version']);
        if ($wrapped === false || $master === '') {
            throw new StorageException('Unable to unwrap the file key with _APP_OPENSSL_KEY_V' . $envelope['version']);
        }

        return Cipher::unwrap($wrapped, $master);
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

    public function range(Device $device, int $start, int $end): string
    {
        return $this->open()->range($device, $this->path(), $this->size(), $start, $end);
    }

    /**
     * @param callable(string): void $sink
     */
    public function stream(Device $device, callable $sink): void
    {
        $this->open()->stream($device, $this->path(), $this->size(), $sink);
    }

    public function copy(Device $from, Device $to, string $target, string $contentType): void
    {
        $this->open()->copy($from, $this->path(), $this->size(), $to, $target, $contentType, APP_LIMIT_UPLOAD_CHUNK_SIZE);
    }

    private function open(): Cipher
    {
        return self::cipher($this->file->getAttribute('metadata', [])['encryption'] ?? throw new StorageException('File ' . $this->file->getId() . ' has no key'));
    }

    private function path(): string
    {
        return $this->file->getAttribute('path', '');
    }

    private function size(): int
    {
        return (int) $this->file->getAttribute('sizeOriginal', 0);
    }
}
