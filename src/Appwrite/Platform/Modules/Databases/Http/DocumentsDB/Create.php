<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB;

use Appwrite\Platform\Modules\Databases\Http\Databases\Create as DatabaseCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Platform\Action;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDocumentsDBDatabase';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/documentsdb')
            ->desc('Create database')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].create')
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'database.create')
            ->label('audits.resource', 'database/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'documentsdb',
                group: 'documentsdb',
                name: 'create',
                description: '/docs/references/documentsdb/create.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: UtopiaResponse::MODEL_DATABASE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
            ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForDatabaseRecords')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $name, bool $enabled, UtopiaResponse $response,  \Utopia\Database\Database $dbForProject ,\Utopia\Database\Database $dbForDatabaseRecords, \Appwrite\Event\Event $queueForEvents): void
    {
        // Ensure the project's metadata 'databases' collection exists
        $metaDatabases = $dbForProject->getCollection('databases');
        if ($metaDatabases->isEmpty()) {
            $projectsCollections = (\Utopia\Config\Config::getParam('collections', [])['projects'] ?? []);
            $databasesSchema = $projectsCollections['databases'] ?? [];
            if (!empty($databasesSchema)) {
                $attributes = [];
                foreach ($databasesSchema['attributes'] ?? [] as $attribute) {
                    $attributes[] = new \Utopia\Database\Document($attribute);
                }
                $indexes = [];
                foreach ($databasesSchema['indexes'] ?? [] as $index) {
                    $indexes[] = new \Utopia\Database\Document($index);
                }
                $dbForProject->createCollection('databases', $attributes, $indexes);
            }
        }

        // Proceed with base create logic
        $databaseId = $databaseId == 'unique()' ? \Utopia\Database\Helpers\ID::unique() : $databaseId;

        try {
            $dbForProject->createDocument('databases', new \Utopia\Database\Document([
                '$id' => $databaseId,
                'name' => $name,
                'enabled' => $enabled,
                'search' => implode(' ', [$databaseId, $name]),
                'type' => 'documentsdb',
            ]));
        } catch (\Utopia\Database\Exception\Duplicate) {
            throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::DATABASE_ALREADY_EXISTS);
        } catch (\Utopia\Database\Exception\Structure $e) {
            throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $database = $dbForProject->getDocument('databases', $databaseId);

        $collections = (\Utopia\Config\Config::getParam('collections', [])['databases'] ?? [])['collections'] ?? [];
        if (empty($collections)) {
            throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::GENERAL_SERVER_ERROR, 'The "collections" collection is not configured.');
        }

        $attributes = [];
        foreach ($collections['attributes'] as $attribute) {
            $attributes[] = new \Utopia\Database\Document($attribute);
        }

        $indexes = [];
        foreach ($collections['indexes'] as $index) {
            $indexes[] = new \Utopia\Database\Document($index);
        }

        try {
            $dbForProject->createCollection('database_' . $database->getSequence(), $attributes, $indexes);
            $dbForDatabaseRecords->createCollection('database_' . $database->getSequence(), $attributes, $indexes);
        } catch (\Utopia\Database\Exception\Duplicate) {
            throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::DATABASE_ALREADY_EXISTS);
        } catch (\Utopia\Database\Exception\Index) {
            throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::INDEX_INVALID);
        } catch (\Utopia\Database\Exception\Limit) {
            throw new \Appwrite\Extend\Exception(\Appwrite\Extend\Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $queueForEvents->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($database, UtopiaResponse::MODEL_DATABASE);
    }
}
