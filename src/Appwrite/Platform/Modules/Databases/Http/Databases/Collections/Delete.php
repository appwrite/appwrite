<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteCollection';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId')
            ->desc('Delete collection')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].delete')
            ->label('audits.event', 'collection.delete')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-collection.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.deleteTable',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$collectionId]);
        }

        if (!$dbForProject->deleteDocument('database_' . $database->getSequence(), $collectionId)) {
            $type = $this->getContext();
            throw new Exception(Exception::GENERAL_SERVER_ERROR, "Failed to remove $type from DB");
        }

        $dbForProject->purgeCachedCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_COLLECTION)
            ->setDatabase($database);

        if ($this->isCollectionsAPI()) {
            $queueForDatabase->setCollection($collection);
        } else {
            $queueForDatabase->setTable($collection);
        }

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setContext('database', $database)
            ->setParam($this->getEventsParamKey(), $collection->getId())
            ->setPayload($response->output($collection, $this->getResponseModel()));

        $response->noContent();
    }
}
