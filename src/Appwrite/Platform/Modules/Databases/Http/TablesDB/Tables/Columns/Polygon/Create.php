<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Polygon;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Polygon\Create as PolygonCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Text;

class Create extends PolygonCreate
{
    public static function getName(): string
    {
        return 'createPolygonColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_POLYGON;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/columns/polygon')
            ->desc('Create polygon column')
            ->groups(['api', 'database', 'schema'])
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('scope', 'tables.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/create-polygon-column.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/tablesdb#tablesDBCreate).')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new \Utopia\Validator\Boolean(), 'Is column required?')
            ->param('default', null, new Text(0, 0), 'Default value for column when not provided. Cannot be set when column is required.', true)
            ->param('array', false, new \Utopia\Validator\Boolean(), 'Is column an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
