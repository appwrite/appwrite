<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Create as DocumentCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;

class Create extends DocumentCreate
{
    public static function getName(): string
    {
        return 'createRow';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW;
    }

    protected function getBulkResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/rows')
            ->desc('Create row')
            ->groups(['api', 'database'])
            ->label('scope', ['rows.write', 'documents.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'row.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: $this->getSDKNamespace(),
                    group: $this->getSDKGroup(),
                    name: self::getName(),
                    desc: 'Create row',
                    description: '/docs/references/tablesdb/create-row.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    parameters: [
                        new Parameter('databaseId', optional: false),
                        new Parameter('tableId', optional: false),
                        new Parameter('rowId', optional: false),
                        new Parameter('data', optional: false),
                        new Parameter('permissions', optional: true),
                        new Parameter('transactionId', optional: true),
                    ]
                ),
                new Method(
                    namespace: $this->getSDKNamespace(),
                    group: $this->getSDKGroup(),
                    name: $this->getBulkActionName(self::getName()),
                    desc: 'Create rows',
                    description: '/docs/references/tablesdb/create-rows.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getBulkResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    parameters: [
                        new Parameter('databaseId', optional: false),
                        new Parameter('tableId', optional: false),
                        new Parameter('rows', optional: false),
                        new Parameter('transactionId', optional: true),
                    ]
                )
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('rowId', '', new CustomId(), 'Row ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', true)
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable). Make sure to define columns before creating rows.')
            ->param('data', [], new JSON(), 'Row data as JSON object.', true, example: '{"username":"walter.obrien","email":"walter.obrien@example.com","fullName":"Walter O\'Brien","age":30,"isAdmin":false}')
            ->param('permissions', null, new Nullable(new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE])), 'An array of permissions strings. By default, only the current user is granted all permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('rows', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of rows data as JSON objects.', true, ['plan'])
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('queueForRealtime')
            ->inject('queueForFunctions')
            ->inject('queueForWebhooks')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
