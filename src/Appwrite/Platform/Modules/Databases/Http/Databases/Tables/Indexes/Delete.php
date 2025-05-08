<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Indexes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes\Delete as IndexDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends IndexDelete
{
    use HTTP;

    /**
     * 1. `SDKResponse` uses `UtopiaResponse::MODEL_NONE`.
     * 2. But we later need the actual return type for events queue below!
     */
    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_COLUMN_INDEX;
    }

    public function __construct()
    {
        $this->setContext(DATABASE_COLUMN_INDEX_CONTEXT);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/indexes/:key')
            ->desc('Delete index')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].indexes.[indexId].update')
            ->label('audits.event', 'index.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
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
            ->callback(function (string $databaseId, string $tableId, string $key, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents) {
                parent::action($databaseId, $tableId, $key, $response, $dbForProject, $queueForDatabase, $queueForEvents);
            });
    }
}
