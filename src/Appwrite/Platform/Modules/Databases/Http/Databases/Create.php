<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
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
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createDatabase';
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
            ->param('databaseId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Database name. Max length: 128 chars.')
            ->param('enabled', true, new Boolean(), 'Is the database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $name, bool $enabled, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents): void
    {
        $databaseId = $databaseId == 'unique()' ? ID::unique() : $databaseId;

        try {
            $dbForProject->createDocument('databases', new Document([
                '$id' => $databaseId,
                'name' => $name,
                'enabled' => $enabled,
                'search' => implode(' ', [$databaseId, $name]),
                'type' => $this->getDatabaseType(),
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS, params: [$databaseId]);
        } catch (StructureException $e) {
            throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, $e->getMessage());
        }

        $database = $dbForProject->getDocument('databases', $databaseId);

        $collections = (Config::getParam('collections', [])['databases'] ?? [])['collections'] ?? [];
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
            throw new Exception(Exception::DATABASE_ALREADY_EXISTS, params: [$databaseId]);
        } catch (IndexException $e) {
            throw new Exception(Exception::INDEX_INVALID);
        } catch (LimitException) {
            // TODO: @Jake, how do we handle this collection/table?
            // there's no context awareness at this level on what the api is.
            throw new Exception(Exception::COLLECTION_LIMIT_EXCEEDED, params: [$databaseId]);
        }

        $queueForEvents->setParam('databaseId', $database->getId());

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($database, UtopiaResponse::MODEL_DATABASE);
    }
}
