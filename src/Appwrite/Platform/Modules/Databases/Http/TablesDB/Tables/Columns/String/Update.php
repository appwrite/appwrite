<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\String;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Update as StringUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends StringUpdate
{
    public static function getName(): string
    {
        return 'updateStringColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_STRING;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/columns/string/:key')
            ->desc('Update string column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', ['tables.write', 'collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
            ->label('audits.event', 'column.update')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/update-string-column.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable).')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for column when not provided. Cannot be set when column is required.')
            ->param('size', null, new Nullable(new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Validator::TYPE_INTEGER)), 'Maximum size of the string column.', true)
            ->param('newKey', null, new Nullable(new Key()), 'New Column Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
