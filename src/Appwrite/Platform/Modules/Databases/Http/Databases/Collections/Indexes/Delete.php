<?php

namespace Appwrite\Platform\Modules\Databases\Http\Indexes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteIndex';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/indexes/:key')
            ->desc('Delete index')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].indexes.[indexId].update')
            ->label('audits.event', 'index.delete')
            // TODO: audits table or collections, check the context type if possible
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'indexes',
                name: 'deleteIndex',
                description: '/docs/references/databases/delete-index.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', '', new Key(), 'Index Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $key, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): void
    {
        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        $index = $dbForProject->getDocument('indexes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key);

        if (empty($index->getId())) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setDatabase($db)
            ->setTable($table)
            ->setRow($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('table', $table)
            ->setContext('database', $db)
            ->setPayload($response->output($index, UtopiaResponse::MODEL_INDEX));

        $response->noContent();
    }
}
