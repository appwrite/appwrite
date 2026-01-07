<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Delete as DocumentDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Nullable;

class Delete extends DocumentDelete
{
    public static function getName(): string
    {
        return 'deleteRow';
    }

    /**
     * Same explanation as the parent action.
     *
     * 1. `SDKResponse` uses `UtopiaResponse::MODEL_NONE`.
     * 2. But we later need the actual return type for events queue below!
     */
    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId')
            ->desc('Delete row')
            ->groups(['api', 'database'])
            ->label('scope', ['rows.write', 'documents.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].delete')
            ->label('audits.event', 'row.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}/row/{request.rowId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/delete-row.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable).')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
