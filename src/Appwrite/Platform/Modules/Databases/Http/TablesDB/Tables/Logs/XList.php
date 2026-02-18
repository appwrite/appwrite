<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Logs;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Logs\XList as CollectionLogXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Types;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;

class XList extends CollectionLogXList
{
    public static function getName(): string
    {
        return 'listTableLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/logs')
            ->desc('List table logs')
            ->groups(['api', 'database'])
            ->label('scope', ['tables.read', 'collections.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/get-table-logs.md',
                auth: [AuthType::ADMIN],
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
            ->param('queries', [], new Types([Query::TYPE_LIMIT, Query::TYPE_OFFSET]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->inject('authorization')
            ->inject('audit')
            ->callback($this->action(...));
    }
}
