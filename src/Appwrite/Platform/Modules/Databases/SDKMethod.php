<?php

namespace Appwrite\Platform\Modules\Databases;

use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;

class SDKMethod
{
    private const array SDK_INFO_MAP = [
        'create'     => ['name' => 'createDatabase', 'docs' => 'create-database'],
        'list'       => ['name' => 'listDatabases', 'docs' => 'list-databases'],
        'get'        => ['name' => 'getDatabase', 'docs' => 'get-database'],
        'update'     => ['name' => 'updateDatabase', 'docs' => 'update-database'],
        'delete'     => ['name' => 'deleteDatabase', 'docs' => 'delete-database'],
        'listLogs'   => ['name' => 'listDatabaseLogs', 'docs' => 'list-database-logs'],
        'listUsage'  => ['name' => 'listDatabaseUsage', 'docs' => 'list-database-usage'],
        'getDatabaseUsage'  => ['name' => 'getDatabaseUsage', 'docs' => 'get-database-usage'],
    ];

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
        return self::SDK_INFO_MAP[$original]['name'] ?? $original;
    }

    private static function transformDocsPathForGrids(string $original): string
    {
        $path = str_replace('/databases/', '/grids/', $original);

        foreach (self::SDK_INFO_MAP as $from => $mapped) {
            if (str_ends_with($path, "/$from.md")) {
                return substr($path, 0, -strlen("$from.md")) . $mapped['docs'] . '.md';
            }
        }

        return $path;
    }
}
