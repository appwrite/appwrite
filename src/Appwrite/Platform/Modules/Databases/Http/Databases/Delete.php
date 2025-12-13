<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteDatabase';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId')
            ->desc('Delete database')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].delete')
            ->label('audits.event', 'database.delete')
            ->label('audits.resource', 'database/{request.databaseId}')
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: 'databases',
                    name: 'delete',
                    description: '/docs/references/databases/delete.md',
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
                        replaceWith: 'tablesDB.delete',
                    )
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        if (!$dbForProject->deleteDocument('databases', $databaseId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove collection from DB');
        }

        $dbForProject->purgeCachedDocument('databases', $database->getId());
        $dbForProject->purgeCachedCollection('databases_' . $database->getSequence());

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_DATABASE)
            ->setDatabase($database);

        $queueForEvents
            ->setParam('databaseId', $database->getId())
            ->setPayload($response->output($database, UtopiaResponse::MODEL_DATABASE));

        $response->noContent();
    }
}
