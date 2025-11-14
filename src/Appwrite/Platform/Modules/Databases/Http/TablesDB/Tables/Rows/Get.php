<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Get as DocumentGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Get extends DocumentGet
{
    public static function getName(): string
    {
        return 'getRow';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_ROW;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId')
            ->desc('Get row')
            ->groups(['api', 'database'])
            ->label('scope', ['rows.read', 'documents.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/get-row.md',
                auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('tableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable).', false, ['dbForProject'])
            ->param('rowId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Row ID.', false, ['dbForProject'])
            ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
            ->param('transactionId', null, new Nullable(new UID()), 'Transaction ID to read uncommitted changes within the transaction.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForStatsUsage')
            ->inject('transactionState')
            ->callback($this->action(...));
    }
}
