<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations;

use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
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
            ->inject('lock')
            ->inject('publisherForMigrations')
            ->callback($this->action(...));
    }

    public function action(
        string $migrationId,
        Response $response,
        Database $dbForProject,
        Document $project,
        array $platform,
        Lock $lock,
        MigrationPublisher $publisherForMigrations
    ): void {
        $key = 'lock:project:' . $project->getSequence() . ':migrations:' . $migrationId . ':retry';

        // Every attempt writes to the same migration-based paths, so a second retry must be
        // refused until this one has moved the migration out of `failed`.
        $migration = $lock->withKey(
            $key,
            function () use ($dbForProject, $migrationId): Document {
                $migration = $dbForProject->getDocument('migrations', $migrationId);

                if ($migration->isEmpty()) {
                    throw new Exception(Exception::MIGRATION_NOT_FOUND);
                }

                if ($migration->getAttribute('status') !== 'failed') {
                    throw new Exception(Exception::MIGRATION_IN_PROGRESS, 'Migration not failed yet');
                }

                return $dbForProject->updateDocument('migrations', $migrationId, new Document([
                    'status' => 'pending',
                ]));
            },
            'migrations.retry',
        );

        try {
            $published = $publisherForMigrations->enqueue(new MigrationMessage(
                project: $project,
                migration: $migration,
                platform: $platform,
            ));
        } catch (\Throwable $error) {
            // The message may still have been queued, so the migration stays `pending`: the stuck
            // migration sweep fails it if no worker ever picks it up, without risking a duplicate run.
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to queue the migration retry.', previous: $error);
        }

        if ($published === false) {
            // The queue refused the message, so nothing will pick the migration up: put it back to
            // `failed` (unless a worker already has it) to keep it retryable.
            $lock->withKey(
                $key,
                function () use ($dbForProject, $migrationId): void {
                    if ($dbForProject->getDocument('migrations', $migrationId)->getAttribute('status') === 'pending') {
                        $dbForProject->updateDocument('migrations', $migrationId, new Document([
                            'status' => 'failed',
                        ]));
                    }
                },
                'migrations.retry',
            );

            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to queue the migration retry.');
        }

        $response->noContent();
    }
}
