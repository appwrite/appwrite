<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;

class DocumentsDB extends Database
{
    public static function getName(): string
    {
        return Resource::TYPE_DATABASE_DOCUMENTSDB;
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     createdAt: string,
     *     updatedAt: string,
     *     enabled: bool,
     *     originalId: string|null,
     *     database?: string,
     *     status: string|null
     * } $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['id'],
            $array['name'],
            createdAt: $array['createdAt'] ?? '',
            updatedAt: $array['updatedAt'] ?? '',
            enabled: $array['enabled'] ?? true,
            originalId: $array['originalId'] ?? '',
            type: $array['type'] ?? Resource::TYPE_DATABASE_DOCUMENTSDB,
            database: $array['database'] ?? '',
            databaseStatus: $array['status'] ?? null
        );
    }
}
