<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Column;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Attribute\Decrement as DecrementDocumentAttribute;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Nullable;
use Utopia\Validator\Numeric;

class Decrement extends DecrementDocumentAttribute
{
    public static function getName(): string
    {
        return 'decrementRowColumn';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId/:column/decrement')
            ->desc('Decrement row column')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].rows.[rowId].update')
            ->label('scope', ['rows.write', 'documents.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'rows.update')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/decrement-row-column.md',
                auth: [AuthType::SESSION, AuthType::JWT, AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('rowId', '', new UID(), 'Row ID.')
            ->param('column', '', new Key(), 'Column key.')
            ->param('value', 1, new Numeric(), 'Value to increment the column by. The value must be a number.', true)
            ->param('min', null, new Nullable(new Numeric()), 'Minimum value for the column. If the current value is lesser than this value, an exception will be thrown.', true)
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID for staging the operation.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
