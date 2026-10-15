<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;

class Collection extends Table
{
    protected ?int $dimension = null;

    public static function getName(): string
    {
        return Resource::TYPE_COLLECTION;
    }

    /**
     * @param array{
     *     database: array{
     *        id: string,
     *        name: string,
     *        type: string
     *     },
     *     name: string,
     *     id: string,
     *     documentSecurity?: bool,
     *     rowSecurity?: bool,
     *     permissions: ?array<string>,
     *     createdAt: string,
     *     updatedAt: string,
     *     enabled: bool,
     *     dimension?: ?int
     * } $array
    */
    public static function fromArray(array $array): self
    {
        $database = match ($array['database']['type']) {
            Resource::TYPE_DATABASE_DOCUMENTSDB => DocumentsDB::fromArray($array['database']),
            Resource::TYPE_DATABASE_VECTORSDB => VectorsDB::fromArray($array['database']),
            default => Database::fromArray($array['database'])
        };

        $collection = new self(
            $database,
            name: $array['name'],
            id: $array['id'],
            rowSecurity: $array['rowSecurity'] ?? $array['documentSecurity'],
            permissions: $array['permissions'] ?? [],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
            enabled: $array['enabled'] ?? true,
        );

        $collection->setDimension($array['dimension'] ?? null);

        return $collection;
    }

    public function getDimension(): ?int
    {
        return $this->dimension;
    }

    public function setDimension(?int $dimension): self
    {
        $this->dimension = $dimension;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        if ($this->dimension !== null) {
            $data['dimension'] = $this->dimension;
        }

        return $data;
    }
}
