<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Platform\Modules\Migrations\Claim;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'retryMigration';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/migrations/:migrationId')
            ->desc('Update retry migration')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].retry')
            ->label('audits.event', 'migration.retry')
            ->label('audits.resource', 'migrations/{request.migrationId}')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'retry',
                description: '/docs/references/migrations/retry-migration.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('migrationId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Migration unique ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('publisherForMigrations')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $migrationId,
        Response $response,
        Database $dbForProject,
        Document $project,
        array $platform,
        MigrationPublisher $publisherForMigrations,
        callable $locks,
    ): void {
        try {
            (new Claim($dbForProject, $locks))->retry(
                project: $project,
                migrationId: $migrationId,
                platform: $platform,
                publisher: $publisherForMigrations,
            );
        } catch (Contention) {
            // A concurrent request holds the claim of this retry and publishes it.
        }

        $response->noContent();
    }
}
