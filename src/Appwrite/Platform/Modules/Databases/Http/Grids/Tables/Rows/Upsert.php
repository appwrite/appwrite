<?php

namespace Appwrite\Platform\Modules\Databases\Http\Grids\Tables\Rows;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Upsert as DocumentUpsert;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\JSON;

class Upsert extends DocumentUpsert
{
    public static function getName(): string
    {
        return 'upsertRow';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/databases/:databaseId/grids/tables/:tableId/rows/:rowId')
            ->desc('Create or update a row')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].upsert')
            ->label('scope', 'rows.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'row.upsert')
            ->label('audits.resource', 'database/{request.databaseId}/grid/table/{request.tableId}/row/{response.$id}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: $this->getSdkNamespace(),
                    group: $this->getSdkGroup(),
                    name: self::getName(),
                    description: '/docs/references/grids/upsert-row.md',
                    auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_CREATED,
                            model: $this->getResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->param('data', [], new JSON(), 'Row data as JSON object. Include all required columns of the row to be created or updated.', true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE, [Database::PERMISSION_READ, Database::PERMISSION_UPDATE, Database::PERMISSION_DELETE, Database::PERMISSION_WRITE]), 'An array of permissions strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('transactionId', null, new UID(), 'Transaction ID for staging the operation.', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }
}
