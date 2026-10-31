<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
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

    /**
     * Scopes that let a caller write each imported resource type; any one of them is enough.
     * Types not listed are project settings and need project.write.
     */
    protected const array SCOPES = [
        Resource::TYPE_USER => ['users.write'],
        Resource::TYPE_HASH => ['users.write'],
        Resource::TYPE_TEAM => ['teams.write'],
        Resource::TYPE_MEMBERSHIP => ['teams.write'],
        Resource::TYPE_POLICIES => ['policies.write', 'project.policies.write'],
        Resource::TYPE_OAUTH2_PROVIDER => ['project.oauth2.write'],
        Resource::TYPE_PLATFORM => ['platforms.write'],
        Resource::TYPE_API_KEY => ['keys.write'],
        Resource::TYPE_WEBHOOK => ['webhooks.write'],
        Resource::TYPE_PROJECT_EMAIL_TEMPLATE => ['templates.write'],
        Resource::TYPE_BUCKET => ['buckets.write'],
        Resource::TYPE_FILE => ['files.write'],
        Resource::TYPE_FUNCTION => ['functions.write'],
        Resource::TYPE_ENVIRONMENT_VARIABLE => ['functions.write'],
        Resource::TYPE_DEPLOYMENT => ['functions.write'],
        Resource::TYPE_SITE => ['sites.write'],
        Resource::TYPE_SITE_VARIABLE => ['sites.write'],
        Resource::TYPE_SITE_DEPLOYMENT => ['sites.write'],
        Resource::TYPE_RULE => ['rules.write'],
        Resource::TYPE_PROVIDER => ['providers.write'],
        Resource::TYPE_TOPIC => ['topics.write'],
        Resource::TYPE_SUBSCRIBER => ['subscribers.write'],
        Resource::TYPE_MESSAGE => ['messages.write'],
        Resource::TYPE_DATABASE => ['databases.write'],
        Resource::TYPE_DATABASE_LEGACY => ['databases.write'],
        Resource::TYPE_DATABASE_TABLESDB => ['databases.write'],
        Resource::TYPE_DATABASE_DOCUMENTSDB => ['documentsdb.write'],
        Resource::TYPE_DATABASE_VECTORSDB => ['vectorsdb.write'],
        Resource::TYPE_TABLE => ['tables.write', 'collections.write'],
        Resource::TYPE_COLUMN => ['tables.write', 'collections.write', 'columns.write', 'attributes.write'],
        Resource::TYPE_INDEX => ['tables.write', 'collections.write', 'indexes.write', 'documentsdb.indexes.write', 'vectorsdb.indexes.write'],
        Resource::TYPE_ROW => ['rows.write', 'documents.write'],
        Resource::TYPE_COLLECTION => ['collections.write', 'documentsdb.collections.write', 'vectorsdb.collections.write'],
        Resource::TYPE_ATTRIBUTE => ['tables.write', 'collections.write', 'columns.write', 'attributes.write'],
        Resource::TYPE_DOCUMENT => ['documents.write', 'documentsdb.documents.write', 'vectorsdb.documents.write'],
    ];

    /**
     * Rejects a key that cannot write every resource the migration imports, and returns
     * the key's scopes for the worker to cap its own key with. Console sessions are not
     * attenuated by key scopes, so they get null.
     *
     * @param array<string> $resources
     * @param array<array<string>> $required further scopes, each satisfied by any one of its members
     * @return array<string>|null
     */
    protected function authorize(Request $request, array $resources, array $required = []): ?array
    {
        if ($request->getHeaderLine('x-appwrite-key') === '') {
            return null;
        }

        foreach ($resources as $resource) {
            $required[] = self::SCOPES[$resource] ?? ['project.write'];
        }

        $scopes = $request->getScopes();

        foreach ($required as $allowed) {
            if (empty(\array_intersect($allowed, $scopes))) {
                throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE, 'API key missing scopes (' . \json_encode($allowed) . ')');
            }
        }

        return \array_values($scopes);
    }

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
