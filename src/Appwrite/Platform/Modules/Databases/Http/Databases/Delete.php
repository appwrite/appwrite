<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Database as DatabaseMessage;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Platform\Action;

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
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('publisherForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, UtopiaResponse $response, Database $dbForProject, DatabasePublisher $publisherForDatabase, Event $queueForEvents): void
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

        $queueForEvents
            ->setParam('databaseId', $database->getId())
            ->setPayload($response->output($database, UtopiaResponse::MODEL_DATABASE));

        $publisherForDatabase->enqueue(new DatabaseMessage(
            project: $queueForEvents->getProject(),
            user: $queueForEvents->getUser(),
            type: DATABASE_TYPE_DELETE_DATABASE,
            database: $database,
            events: Event::generateEvents($queueForEvents->getEvent(), $queueForEvents->getParams()),
        ));

        $response->noContent();
    }
}
