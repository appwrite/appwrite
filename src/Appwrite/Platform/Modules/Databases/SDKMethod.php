<?php

namespace Appwrite\Platform\Modules\Databases;

use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;

class SDKMethod
{
    /**
     * @return Method[]
     */
    public static function withGridsAPI(
        string      $namespace,
        ?string     $group,
        string      $name,
        string      $description,
        array       $auth,
        array       $responses,
        ContentType $contentType,
    ): array {
        return [
            new Method(
                namespace: $namespace,
                group: $group,
                name: $name,
                description: $description,
                auth: $auth,
                responses: $responses,
                contentType: $contentType,
            ),
            new Method(
                namespace: 'grids',
                group: $group === null ? null : 'grids',
                name: self::transformNameForGrids($name),
                description: self::transformDocsPathForGrids($description),
                auth: $auth,
                responses: $responses,
                contentType: $contentType,
            )
        ];
    }

    private static function transformNameForGrids(string $original): string
    {
        return match ($original) {
            'create' => 'createDatabase',
            'list'   => 'listDatabases',
            'get'    => 'getDatabase',
            'update' => 'updateDatabase',
            'delete' => 'deleteDatabase',
            'listLogs' => 'listDatabaseLogs',
            'listUsage' => 'listDatabaseUsage',
            default  => $original /* `getDatabaseUsage` is already correct! */
        };
    }

    private static function transformDocsPathForGrids(string $original): string
    {
        return str_replace('/databases/', '/grids/', $original);
    }
}
