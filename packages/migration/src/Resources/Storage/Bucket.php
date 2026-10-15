<?php

namespace Utopia\Migration\Resources\Storage;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class Bucket extends Resource
{
    /**
     * @param string $id
     * @param string $name
     * @param array<string> $permissions
     * @param bool $fileSecurity
     * @param bool $enabled
     * @param int|null $maxFileSize
     * @param array<string> $allowedFileExtensions
     * @param string $compression
     * @param bool $encryption
     * @param bool $antiVirus
     * @param bool $updateLimits
     * @param bool $transformations
     */
    public function __construct(
        string $id = '',
        private readonly string $name = '',
        array $permissions = [],
        private readonly bool $fileSecurity = false,
        private readonly bool $enabled = false,
        private readonly ?int $maxFileSize = null,
        private readonly array $allowedFileExtensions = [],
        private readonly string $compression = 'none',
        private readonly bool $encryption = false,
        private readonly bool $antiVirus = false,
        private readonly bool $updateLimits = false,
        private readonly bool $transformations = false,
    ) {
        $this->id = $id;
        $this->permissions = $permissions;
    }

    /**
     * @param array<string, mixed> $array
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['name'] ?? '',
            $array['permissions'] ?? [],
            $array['fileSecurity'] ?? false,
            $array['enabled'] ?? false,
            $array['maxFileSize'] ?? null,
            $array['allowedFileExtensions'] ?? [],
            $array['compression'] ?? 'none',
            $array['encryption'] ?? false,
            $array['antiVirus'] ?? false,
            $array['updateLimits'] ?? false,
            $array['transformations'] ?? false
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'fileSecurity' => $this->fileSecurity,
            'enabled' => $this->enabled,
            'maxFileSize' => $this->maxFileSize,
            'allowedFileExtensions' => $this->allowedFileExtensions,
            'compression' => $this->compression,
            'encryption' => $this->encryption,
            'antiVirus' => $this->antiVirus,
            'updateLimits' => $this->updateLimits,
            'transformations' => $this->transformations,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_BUCKET;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_STORAGE;
    }

    public function getFileSecurity(): bool
    {
        return $this->fileSecurity;
    }

    public function getBucketName(): string
    {
        return $this->name;
    }


    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMaxFileSize(): ?int
    {
        return $this->maxFileSize;
    }


    /**
     * @return array<string>
     */
    public function getAllowedFileExtensions(): array
    {
        return $this->allowedFileExtensions;
    }

    public function getCompression(): string
    {
        return $this->compression;
    }

    public function getEncryption(): bool
    {
        return $this->encryption;
    }

    public function getAntiVirus(): bool
    {
        return $this->antiVirus;
    }

    public function getTransformations(): bool
    {
        return $this->transformations;
    }
}
