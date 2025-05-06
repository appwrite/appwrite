<?php

namespace Appwrite\Platform\Modules\Databases\Http\Tables;

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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteTable';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId')
            ->desc('Delete table')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].delete')
            ->label('audits.event', 'table.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'tables',
                name: 'deleteTable',
                description: '/docs/references/databases/delete-collection.md',
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
            ->param('tableId', '', new UID(), 'Table ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        if ($table->isEmpty()) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('database_' . $database->getInternalId(), $tableId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->purgeCachedCollection('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_COLLECTION)
            ->setDatabase($database)
            ->setTable($table);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setPayload($response->output($table, UtopiaResponse::MODEL_TABLE));

        $response->noContent();
    }
}
