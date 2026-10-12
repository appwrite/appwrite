<?php

namespace Utopia\Migration\Resources\Storage;

use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;

class File extends Resource
{
    /**
     * @param string $id
     * @param Bucket $bucket
     * @param string $name
     * @param string $signature
     * @param string $mimeType
     * @param array<string> $permissions
     * @param int $size
     * @param string $data
     * @param int $start
     * @param int $end
     * @param string $folder
     */
    public function __construct(
        string $id,
        private readonly Bucket $bucket,
        private readonly string $name = '',
        private readonly string $signature = '',
        private readonly string $mimeType = '',
        array $permissions = [],
        private readonly int $size = 0,
        private string $data = '',
        private int $start = 0,
        private int $end = 0,
        private readonly string $folder = ''
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
            Bucket::fromArray($array['bucket']),
            $array['name'] ?? '',
            $array['signature'] ?? '',
            $array['mimeType'] ?? '',
            $array['permissions'] ?? [],
            $array['size'] ?? 0,
            $array['data'] ?? '',
            $array['start'] ?? 0,
            $array['end'] ?? 0,
            $array['folder'] ?? ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'bucket' => $this->bucket,
            'name' => $this->name,
            'signature' => $this->signature,
            'mimeType' => $this->mimeType,
            'permissions' => $this->permissions,
            'size' => $this->size,
            'start' => $this->start,
            'end' => $this->end,
            'folder' => $this->folder,
        ];
    }

    public static function getName(): string
    {
        return Resource::TYPE_FILE;
    }

    public function getGroup(): string
    {
        return Transfer::GROUP_STORAGE;
    }

    public function getBucket(): Bucket
    {
        return $this->bucket;
    }

    public function getFileName(): string
    {
        return $this->name;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getStart(): int
    {
        return $this->start;
    }

    public function setStart(int $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): int
    {
        return $this->end;
    }

    public function setEnd(int $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function getSizeInBytes(): int
    {
        return strlen($this->data);
    }

    public function getChunkSize(): int
    {
        return $this->end - $this->start;
    }
}
