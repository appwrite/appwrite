<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;

class Update extends Action
{
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
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('migrationId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Migration unique ID.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('publisherForMigrations')
            ->callback($this->action(...));
    }

    public function action(
        string $migrationId,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        array $platform,
        MigrationPublisher $publisherForMigrations
    ): void {
        $migration = $dbForProject->getDocument('migrations', $migrationId);

        if ($migration->isEmpty()) {
            throw new Exception(Exception::MIGRATION_NOT_FOUND);
        }

        // A worker killed mid-run (restart, OOM) leaves its migration processing for good.
        $status = $migration->getAttribute('status');
        $stalled = $status === 'processing'
            && \strtotime($migration->getUpdatedAt()) < \time() - MIGRATION_STALL_TIMEOUT;

        if ($status !== 'failed' && !$stalled) {
            throw new Exception(Exception::MIGRATION_IN_PROGRESS, 'Migration not failed yet');
        }

        // A retry runs with no more than the retrying caller holds; a session keeps the recorded grant.
        // Only a key-created grant was checked against the resources, so others are checked here.
        $options = $migration->getAttribute('options', []);
        $recorded = $options['scopes'] ?? null;
        $grant = $this->authorize($request, \is_array($recorded) ? [] : $migration->getAttribute('resources', []));

        if ($grant !== null) {
            $options['scopes'] = \is_array($recorded) ? \array_values(\array_intersect($recorded, $grant)) : $grant;
        } elseif (!\array_key_exists('scopes', $options)) {
            $options['scopes'] = null;
        }

        $migration->setAttribute('options', $options);

        $migration
            ->setAttribute('status', 'pending')
            ->setAttribute('dateUpdated', \time());

        $publisherForMigrations->enqueue(new MigrationMessage(
            project: $project,
            migration: $migration,
            platform: $platform,
        ));

        $response->noContent();
    }
}
