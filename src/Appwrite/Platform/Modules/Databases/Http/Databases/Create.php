<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Pool;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Helpers\ID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDatabase';
    }

    protected function getDatabaseDSN(Document $project): string
    {
        // TODO: use database worker for for creating the v2 schema if not present
        // it is considered that the v2 metadata schema is already created during server start in the http.php
        return Pool::dsn($this->getDatabaseType(), (string) $project->getAttribute('region', 'default'), $project->getAttribute('database'));
    }

    protected function getDatabaseCollection()
    {
        return match ($this->getDatabaseType()) {
            'vectorsdb' => (Config::getParam('collections', [])['vectorsdb'] ?? [])['collections'] ?? [],
            default => (Config::getParam('collections', [])['databases'] ?? [])['collections'] ?? [],
        };
    }
    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases')
            ->desc('Create database')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].create')
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'database.create')
            ->label('audits.resource', 'database/{response.$id}')
            ->label('usage.resource', 'database/{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: 'databases',
                    name: 'create',
                    description: '/docs/references/databases/create.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: UtopiaResponse::MODEL_DATABASE,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.create',
                    )
                )
            ])
            ->param('databaseId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
            ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $name, bool $enabled, Document $project, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents): void
    {
        $databaseId = $databaseId == 'unique()' ? ID::unique() : $databaseId;

        try {
            $dbForProject->createDocument('databases', new Document([
                '$id' => $databaseId,
                'name' => $name,
                'enabled' => $enabled,
                'search' => implode(' ', [$databaseId, $name]),
                'type' => $this->getDatabaseType(),
                'database' => $this->getDatabaseDSN($project),
                'status' => 'ready',
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS, params: [$databaseId]);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $database = $dbForProject->getDocument('databases', $databaseId);

        $this->createMetadataCollection($dbForProject, $database);

        $queueForEvents->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($database, UtopiaResponse::MODEL_DATABASE);
    }

    /**
     * Create the per-database metadata collection (`database_{internalId}`) that holds
     * the database's collection definitions. Extracted so product overrides that
     * provision a backend asynchronously (e.g. documentsdb, vectorsdb) can initialise
     * the metadata exactly like a standard database create instead of leaving it
     * uninitialised, which makes the first collection create fail.
     */
    protected function createMetadataCollection(Database $dbForProject, Document $database): void
    {
        $collections = $this->getDatabaseCollection();
        if (empty($collections)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'The "collections" collection is not configured.');
        }

        $attributes = [];
        foreach ($collections['attributes'] as $attribute) {
            $attributes[] = new Document($attribute);
        }

        $indexes = [];
        foreach ($collections['indexes'] as $index) {
            $indexes[] = new Document($index);
        }

        try {
            $dbForProject->createCollection('database_' . $database->getSequence(), $attributes, $indexes);
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS, params: [$database->getId()]);
        } catch (IndexException $e) {
            throw new Exception(Exception::INDEX_INVALID);
        } catch (LimitException) {
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED, params: [$database->getId()]);
        }
    }
}
