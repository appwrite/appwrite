<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Utopia\Database\Adapter\Profile;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Queries\Documents;
use Utopia\Migration\Resource;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action as PlatformAction;
use Utopia\Platform\Scope\HTTP;

abstract class Action extends PlatformAction
{
    use HTTP;

    protected static function transferGroupForDatabaseType(string $databaseType): string
    {
        return match ($databaseType) {
            DATABASE_TYPE_LEGACY,
            DATABASE_TYPE_TABLESDB => Transfer::GROUP_DATABASES_TABLES_DB,
            DATABASE_TYPE_VECTORSDB => Transfer::GROUP_DATABASES_VECTOR_DB,
            DATABASE_TYPE_DOCUMENTSDB => Transfer::GROUP_DATABASES_DOCUMENTS_DB,
            default => throw new \LogicException('Unknown database type: ' . $databaseType),
        };
    }

    protected static function resourceTypeForDatabaseType(string $databaseType): string
    {
        return match ($databaseType) {
            DATABASE_TYPE_VECTORSDB => Resource::TYPE_DATABASE_VECTORSDB,
            DATABASE_TYPE_DOCUMENTSDB => Resource::TYPE_DATABASE_DOCUMENTSDB,
            default => Resource::TYPE_DATABASE,
        };
    }

    protected function exportQueriesValidator(Database $dbForProject, Document $collection, string $databaseType): Documents
    {
        $isSchemaless = \in_array($databaseType, [DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB], true);
        $profile = $dbForProject->profile();

        return new Documents(
            attributes: $collection->getAttribute('attributes', []),
            indexes: $collection->getAttribute('indexes', []),
            profile: new Profile(
                limits: $profile->limits,
                capabilities: $profile->capabilities,
                features: $profile->features,
                sharedTables: $profile->sharedTables,
                migrating: $profile->migrating,
                definedAttributes: static fn (): bool => !$isSchemaless,
            ),
        );
    }
}
