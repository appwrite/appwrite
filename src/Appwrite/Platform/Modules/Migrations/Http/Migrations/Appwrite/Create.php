<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Appwrite;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createAppwriteMigration';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/migrations/appwrite')
            ->desc('Create Appwrite migration')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].create')
            ->label('audits.event', 'migration.create')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'createAppwriteMigration',
                description: '/docs/references/migrations/migration-appwrite.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('resources', [], new ArrayList(new WhiteList(AppwriteSource::getSupportedResources())), 'List of resources to migrate')
            ->param('endpoint', '', new URL(), 'Source Appwrite endpoint')
            ->param('projectId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Source Project ID', false, ['dbForProject'])
            ->param('apiKey', '', new Text(512), 'Source API Key')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('queueForEvents')
            ->inject('publisherForMigrations')
            ->callback($this->action(...));
    }

    public function action(
        array $resources,
        string $endpoint,
        string $projectId,
        string $apiKey,
        Response $response,
        Database $dbForProject,
        Document $project,
        array $platform,
        Event $queueForEvents,
        MigrationPublisher $publisherForMigrations
    ): void {
        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => ID::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => AppwriteSource::getName(),
            'destination' => AppwriteSource::getName(),
            'credentials' => [
                'endpoint' => $endpoint,
                'projectId' => $projectId,
                'apiKey' => $apiKey,
            ],
            'resources' => $resources,
            'statusCounters' => '{}',
            'resourceData' => '{}',
            'errors' => [],
        ]));

        $queueForEvents->setParam('migrationId', $migration->getId());

        $publisherForMigrations->enqueue(new MigrationMessage(
            project: $project,
            migration: $migration,
            platform: $platform,
        ));

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($migration, Response::MODEL_MIGRATION);
    }
}
