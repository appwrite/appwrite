<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteMigration';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/migrations/:migrationId')
            ->desc('Delete migration')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].delete')
            ->label('audits.event', 'migrationId.delete')
            ->label('audits.resource', 'migrations/{request.migrationId}')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'delete',
                description: '/docs/references/migrations/delete-migration.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('migrationId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Migration ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $migrationId, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('migrations', $migration->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove migration from DB');
        }

        $queueForEvents->setParam('migrationId', $migration->getId());

        $response->noContent();
    }
}
