<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class UploadedFile implements UploadedFileInterface
{
    private ?StreamInterface $stream = null;

    private bool $moved = false;

    public function __construct(
        private readonly string $file,
        private readonly int $size,
        private readonly int $error,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {}

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    public static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $file) {
            if (!\is_array($file)) {
                continue;
            }

            $normalized[$key] = self::isFileSpec($file)
                ? self::normalizeFileSpec($file)
                : self::normalizeFiles($file);
        }

        return $normalized;
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded file is not available.');
        }

        if (!$this->stream instanceof StreamInterface) {
            $resource = fopen($this->file, 'r');

            if (!\is_resource($resource)) {
                throw new RuntimeException('Unable to open uploaded file stream.');
            }

            $this->stream = Stream::fromResource($resource);
        }

        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        if ($targetPath === '') {
            throw new InvalidArgumentException('Target path must not be empty.');
        }

        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved.');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded file is not available.');
        }

        $moved = \PHP_SAPI === 'cli'
            ? rename($this->file, $targetPath)
            : move_uploaded_file($this->file, $targetPath);

        if (!$moved) {
            throw new RuntimeException('Unable to move uploaded file.');
        }

        $this->moved = true;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * @param array<array-key, mixed> $file
     */
    private static function isFileSpec(array $file): bool
    {
        return \array_key_exists('tmp_name', $file)
            || \array_key_exists('error', $file)
            || \array_key_exists('size', $file);
    }

    /**
     * @param array<array-key, mixed> $file
     * @return UploadedFileInterface|array<array-key, mixed>
     */
    private static function normalizeFileSpec(array $file): UploadedFileInterface|array
    {
        if (\is_array($file['tmp_name'] ?? null)) {
            $normalized = [];

            foreach (array_keys($file['tmp_name']) as $key) {
                $normalized[$key] = self::normalizeFileSpec([
                    'tmp_name' => $file['tmp_name'][$key] ?? '',
                    'size' => \is_array($file['size'] ?? null) ? ($file['size'][$key] ?? 0) : 0,
                    'error' => \is_array($file['error'] ?? null) ? ($file['error'][$key] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE,
                    'name' => \is_array($file['name'] ?? null) ? ($file['name'][$key] ?? null) : null,
                    'type' => \is_array($file['type'] ?? null) ? ($file['type'][$key] ?? null) : null,
                ]);
            }

            return $normalized;
        }

        return new self(
            self::stringValue($file['tmp_name'] ?? ''),
            self::intValue($file['size'] ?? 0),
            self::intValue($file['error'] ?? UPLOAD_ERR_NO_FILE),
            self::nullableStringValue($file['name'] ?? null),
            self::nullableStringValue($file['type'] ?? null),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    private static function nullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private static function intValue(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) || \is_string($value)) {
            return (int) $value;
        }

        return 0;
    }
}
