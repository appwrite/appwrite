<?php

namespace Appwrite\Platform\Modules\Databases\Http\Grids\Tables\Columns\Datetime;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Datetime\Create as DatetimeCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class Create extends DatetimeCreate
{
    public static function getName(): string
    {
        return 'createDatetimeColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_DATETIME;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/grids/tables/:tableId/columns/datetime')
            ->desc('Create datetime column')
            ->groups(['api', 'database'])
            ->label('scope', 'tables.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/grid/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/grids/create-datetime-column.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, fn (Database $dbForProject) => new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime()), 'Default value for the column in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Cannot be set when column is required.', true, ['dbForProject'])
            ->param('array', false, new Boolean(), 'Is column an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
