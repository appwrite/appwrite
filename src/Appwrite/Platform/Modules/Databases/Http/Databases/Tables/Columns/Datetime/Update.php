<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Columns\Datetime;

use Appwrite\Platform\Modules\Databases\Context;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Datetime\Update as DatetimeUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;

class Update extends DatetimeUpdate
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateDatetimeColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_DATETIME;
    }

    public function __construct()
    {
        $this->setContext(Context::DATABASE_COLUMNS);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/datetime/:key')
            ->desc('Update dateTime column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
            ->label('audits.event', 'column.update')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-datetime-column.md',
                auth: [AuthType::KEY],
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
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, fn (Database $dbForProject) => new Nullable(new DatetimeValidator($dbForProject->getAdapter()->getMinDateTime(), $dbForProject->getAdapter()->getMaxDateTime())), 'Default value for column when not provided. Cannot be set when column is required.', injections: ['dbForProject'])
            ->param('newKey', null, new Key(), 'New Column Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
