<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getMigration';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/migrations/:migrationId')
            ->desc('Get migration')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.read')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'get',
                description: '/docs/references/migrations/get-migration.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('migrationId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Migration unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $migrationId, Response $response, Database $dbForProject): void
    {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        $response->dynamic($migration, Response::MODEL_MIGRATION);
    }
}
