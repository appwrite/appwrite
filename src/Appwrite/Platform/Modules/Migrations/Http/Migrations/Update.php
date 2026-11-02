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
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Sources\JSON;

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

        // A retry re-runs the migration's work, so the retrying key is checked against it again,
        // whatever the creating caller held. A session is not attenuated and keeps the grant.
        $options = $migration->getAttribute('options', []);
        $recorded = $options['scopes'] ?? null;
        [$resources, $required] = $this->requirement($migration);
        $grant = $this->authorize($request, $resources, $required);

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

    /**
     * Resources and scopes a retry must satisfy, mirroring what creating this migration
     * required: a CSV or JSON migration only reads or writes the rows of one database
     * family, every other migration writes each of its resources. An imported file was
     * already copied out of its bucket at creation, so files.read is not required again.
     *
     * @return array{array<string>, array<array<string>>}
     */
    private function requirement(Document $migration): array
    {
        $file = [CSV::getName(), JSON::getName()];
        $family = $migration->getAttribute('parentResourceType');

        if (\in_array($migration->getAttribute('destination'), $file, true)) {
            return [[], [match ($family) {
                Resource::TYPE_DATABASE_DOCUMENTSDB => ['documentsdb.documents.read'],
                Resource::TYPE_DATABASE_VECTORSDB => ['vectorsdb.documents.read'],
                default => ['rows.read', 'documents.read'],
            }]];
        }

        if (\in_array($migration->getAttribute('source'), $file, true)) {
            return [[], [match ($family) {
                Resource::TYPE_DATABASE_DOCUMENTSDB => ['documentsdb.documents.write'],
                Resource::TYPE_DATABASE_VECTORSDB => ['vectorsdb.documents.write'],
                default => ['rows.write', 'documents.write'],
            }]];
        }

        return [$migration->getAttribute('resources', []), []];
    }
}
