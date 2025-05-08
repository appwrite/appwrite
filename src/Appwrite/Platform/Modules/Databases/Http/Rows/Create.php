<?php

namespace Appwrite\Platform\Modules\Databases\Http\Rows;

use Appwrite\Event\Event;
use Appwrite\Event\StatsUsage;
use Appwrite\Platform\Modules\Databases\Http\Documents\Create as DocumentCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\JSON;

class Create extends DocumentCreate
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRow';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW;
    }

    public function __construct()
    {
        $this->setContext(DATABASE_ROWS_CONTEXT);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/rows')
            ->desc('Create row')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].create')
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'row.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: 'databases',
                    group: $this->getSdkGroup(),
                    name: self::getName(),
                    description: '/docs/references/databases/create-document.md',
                    auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: self::getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('rowId', '', new CustomId(), 'Row ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection). Make sure to define columns before creating rows.')
            ->param('data', [], new JSON(), 'Row data as JSON object.')
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback(function (string $databaseId, string $rowId, string $tableId, string|array $data, ?array $permissions, UtopiaResponse $response, Database $dbForProject, Document $user, Event $queueForEvents, StatsUsage $queueForStatsUsage) {
                parent::action($databaseId, $rowId, $tableId, $databaseId, $permissions, $response, $dbForProject, $user, $queueForEvents, $queueForStatsUsage);
            });
    }
}
