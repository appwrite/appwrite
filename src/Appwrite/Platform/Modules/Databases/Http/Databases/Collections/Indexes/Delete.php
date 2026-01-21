<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes;

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
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteIndex';
    }

    /**
     * 1. `SDKResponse` uses `UtopiaResponse::MODEL_NONE`.
     * 2. But we later need the actual return type for events queue below!
     */
    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_INDEX;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
            ->desc('Delete index')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].update')
            ->label('audits.event', 'index.delete')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-index.md',
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
                    replaceWith: 'tablesDB.deleteIndex',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', '', new Key(), 'Index Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }
        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            // table or collection.
            throw new Exception($this->getGrandParentNotFoundException(), params: [$collectionId]);
        }

        $index = $dbForProject->getDocument('indexes', $db->getSequence() . '_' . $collection->getSequence() . '_' . $key);

        if (empty($index->getId())) {
            throw new Exception($this->getNotFoundException(), params: [$key]);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setDatabase($db);

        if ($this->isCollectionsAPI()) {
            $queueForDatabase
                ->setCollection($collection)
                ->setDocument($index);
        } else {
            $queueForDatabase
                ->setTable($collection)
                ->setRow($index);
        }

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('indexId', $index->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('collectionId', $collection->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection)
            ->setPayload($response->output($index, $this->getResponseModel()));

        $response->noContent();
    }
}
