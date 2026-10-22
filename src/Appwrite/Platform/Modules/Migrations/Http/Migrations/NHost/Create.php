<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\NHost;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Migration as MigrationMessage;
use Appwrite\Event\Publisher\Migration as MigrationPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Label;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Migration\Sources\NHost;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Migration\Sources\Appwrite as AppwriteSource;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Identifier;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createNHostMigration';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/migrations/nhost')
            ->desc('Create NHost migration')
            ->groups(['api', 'migrations'])
            ->label('scope', 'migrations.write')
            ->label('event', 'migrations.[migrationId].create')
            ->label('audits.event', 'migration.create')
            ->label('sdk', new Method(
                namespace: 'migrations',
                group: null,
                name: 'createNHostMigration',
                description: '/docs/references/migrations/migration-nhost.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_MIGRATION,
                    )
                ]
            ))
            ->param('resources', [], new ArrayList(new WhiteList(NHost::getSupportedResources())), 'List of resources to migrate', example: '["user"]', enum: new Enum(name: 'NHostMigrationResource'))
            ->param('subdomain', '', new Label(), 'Source\'s Subdomain')
            ->param('region', '', new Label(), 'Source\'s Region')
            ->param('adminSecret', '', new Text(512), 'Source\'s Admin Secret')
            ->param('database', '', new Identifier(63), 'Source\'s Database Name')
            ->param('username', '', new Text(512), 'Source\'s Database Username')
            ->param('password', '', new PasswordFormat(new Text(512)), 'Source\'s Database Password')
            ->param('port', 5432, new Integer(true), 'Source\'s Database Port', true, example: '5432')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('queueForEvents')
            ->inject('publisherForMigrations')
            ->inject('publicHostname')
            ->callback($this->action(...));
    }

    public function action(
        array $resources,
        string $subdomain,
        string $region,
        string $adminSecret,
        string $database,
        string $username,
        string $password,
        int $port,
        Response $response,
        Database $dbForProject,
        Document $project,
        array $platform,
        Event $queueForEvents,
        MigrationPublisher $publisherForMigrations,
        PublicHostname $publicHostname
    ): void {
        // The source hostnames are built from the subdomain and region; refuse any that resolve to a private or reserved address
        foreach ([NHost::databaseHost($subdomain, $region), NHost::storageHost($subdomain, $region)] as $host) {
            if (!$publicHostname->isValid($host)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $publicHostname->getDescription());
            }
        }

        $migration = $dbForProject->createDocument('migrations', new Document([
            '$id' => Id::unique(),
            'status' => 'pending',
            'stage' => 'init',
            'source' => NHost::getName(),
            'destination' => AppwriteSource::getName(),
            'credentials' => [
                'subdomain' => $subdomain,
                'region' => $region,
                'adminSecret' => $adminSecret,
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'port' => $port,
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
