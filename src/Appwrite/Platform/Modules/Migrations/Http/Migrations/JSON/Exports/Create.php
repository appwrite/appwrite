<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\JSON\Exports;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CompoundUID;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries\Documents;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;
use Utopia\Migration\Sources\JSON as JSONSource;
use Utopia\Migration\Transfer;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createJSONExport';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/migrations/json/exports')
            ->desc('Export documents to JSON')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].create')
            ->label('audits.event', 'migration.create')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'createJSONExport',
                description: '/docs/references/migrations/migration-json-export.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('resourceId', null, new CompoundUID(), 'Composite ID in the format {databaseId:collectionId}, identifying a collection within a database to export.')
            ->param('filename', '', new Text(255), 'The name of the file to be created for the export, excluding the .json extension.')
            ->param('columns', [], new ArrayList(new Text(Database::LENGTH_KEY)), 'List of attributes to export. If empty, all attributes will be exported. You can use the `*` wildcard to export all attributes from the collection.', true)
            ->param('queries', [], new ArrayList(new Text(0)), 'Array of query strings generated using the Query class provided by the SDK to filter documents to export. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('notify', true, new Boolean(), 'Set to true to receive an email when the export is complete. Default is true.', true)
            ->inject('user')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->inject('project')
            ->inject('platform')
            ->inject('queueForEvents')
            ->inject('publisherForMigrations')
            ->callback($this->action(...));
    }

    public function action(
        string $resourceId,
        string $filename,
        array $columns,
        array $queries,
        bool $notify,
        Document $user,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Authorization $authorization,
        Document $project,
        array $platform,
        Event $queueForEvents,
        MigrationPublisher $publisherForMigrations
    ): void {
        try {
            $parsedQueries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $bucket = $authorization->skip(fn () => $dbForPlatform->getDocument('buckets', 'default'));
        if ($bucket->isEmpty()) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        [$databaseId, $collectionId] = \explode(':', $resourceId, 2);
        if (empty($databaseId)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        if (empty($collectionId)) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $authorization->skip(fn () => $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId));
        if ($collection->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $databaseType = $database->getAttribute('type');

        // Schemaless databases (DocumentsDB, VectorsDB) allow queries on dynamic fields
        $isSchemaless = \in_array($databaseType, [DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB]);

        $validator = new Documents(
            attributes: $collection->getAttribute('attributes', []),
            indexes: $collection->getAttribute('indexes', []),
            idAttributeType: $dbForProject->getAdapter()->getIdAttributeType(),
            supportForAttributes: !$isSchemaless,
        );

        if (!$validator->isValid($parsedQueries)) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
        }

        $resources = Transfer::extractServices([self::transferGroupForDatabaseType($databaseType)]);
        $resourceType = self::resourceTypeForDatabaseType($databaseType);

        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => AppwriteSource::getName(),
            'destination' => JSONSource::getName(),
            'resources' => $resources,
            'resourceId' => $resourceId,
            'resourceType' => $resourceType,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
            'options' => [
                'bucketId' => 'default', // Always use internal bucket
                'filename' => $filename,
                'columns' => $columns,
                'queries' => $queries,
                'notify' => $notify,
                'userInternalId' => $user->getSequence(),
            ],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        $publisherForMigrations->enqueue(new MigrationMessage(
            project: $project,
            migration: $migration,
            platform: $platform,
        ));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    }

    private static function transferGroupForDatabaseType(string $databaseType): string
    {
        return match ($databaseType) {
            DATABASE_TYPE_LEGACY,
            DATABASE_TYPE_TABLESDB => Transfer::GROUP_DATABASES_TABLES_DB,
            DATABASE_TYPE_VECTORSDB => Transfer::GROUP_DATABASES_VECTOR_DB,
            DATABASE_TYPE_DOCUMENTSDB => Transfer::GROUP_DATABASES_DOCUMENTS_DB,
            default => throw new \LogicException('Unknown database type: ' . $databaseType),
        };
    }

    private static function resourceTypeForDatabaseType(string $databaseType): string
    {
        return match ($databaseType) {
            DATABASE_TYPE_VECTORSDB => Resource::TYPE_DATABASE_VECTORSDB,
            DATABASE_TYPE_DOCUMENTSDB => Resource::TYPE_DATABASE_DOCUMENTSDB,
            default => Resource::TYPE_DATABASE,
        };
    }
}
