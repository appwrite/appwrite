<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

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
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateDatabase';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/databases/:databaseId')
            ->desc('Update database')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].update')
            ->label('audits.event', 'database.update')
            ->label('audits.resource', 'database/{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: 'databases',
                    name: 'update',
                    description: '/docs/references/databases/update.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_DATABASE,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'tablesDB.update',
                    )
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('name', null, new Text(128), 'Database name. Max length: 128 chars.')
            ->param('enabled', true, new Boolean(), 'Is database enabled? When set to \'disabled\', users cannot access the database but Server SDKs with an API key can still read and write to the database. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $name, bool $enabled, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents): void
    {
        $database = $dbForProject->getDocument('databases', $databaseId);

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $database = $dbForProject->updateDocument('databases', $databaseId, $database
            ->setAttribute('name', $name)
            ->setAttribute('enabled', $enabled)
            ->setAttribute('search', implode(' ', [$databaseId, $name])));

        $queueForEvents->setParam('databaseId', $database->getId());

        $response->dynamic($database, UtopiaResponse::MODEL_DATABASE);
    }
}
