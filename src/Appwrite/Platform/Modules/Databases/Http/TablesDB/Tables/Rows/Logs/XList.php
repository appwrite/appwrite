<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Rows\Logs;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Logs\XList as DocumentLogXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;

class XList extends DocumentLogXList
{
    public static function getName(): string
    {
        return 'listRowLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/rows/:rowId/logs')
            ->desc('List row logs')
            ->groups(['api', 'database'])
            ->label('scope', ['rows.read', 'documents.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: 'logs',
                name: self::getName(),
                description: '/docs/references/tablesdb/get-row-logs.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('tableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Table ID.', false, ['dbForProject'])
            ->param('rowId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Row ID.', false, ['dbForProject'])
            ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('locale')
            ->inject('geodb')
            ->inject('authorization')
            ->inject('audit')
            ->callback($this->action(...));
    }
}
