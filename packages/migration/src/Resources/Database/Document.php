<?php

namespace Utopia\Migration\Resources\Database;

use Utopia\Migration\Resource;

class Document extends Row
{
    public static function getName(): string
    {
        return Resource::TYPE_DOCUMENT;
    }

    /**
     * @param array{
     *     id: string,
     *     collection?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         documentSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     table?: array{
     *         database: array{
     *             id: string,
     *             name: string,
     *         },
     *         name: string,
     *         id: string,
     *         rowSecurity: bool,
     *         permissions: ?array<string>
     *     },
     *     data: array<string, mixed>,
     *     permissions: ?array<string>
     * } $array
    */
    public static function fromArray(array $array): self
    {
        // keeping table and collection to have backward compat
        return new self(
            $array['id'],
            Collection::fromArray($array['table'] ?? $array['collection']),
            $array['data'],
            $array['permissions'] ?? []
        );
    }
}
