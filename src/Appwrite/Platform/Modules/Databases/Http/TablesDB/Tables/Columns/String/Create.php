<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\String;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Create as StringCreate;
use Appwrite\SDK\AuthType;
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

class Create extends StringCreate
{
    public static function getName(): string
    {
        return 'createStringColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_STRING;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/columns/string')
            ->desc('Create string column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', ['tables.write', 'collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/create-string-column.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/references/cloud/server-dart/tablesDB#createTable).')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Validator::TYPE_INTEGER), 'Column size for text columns, in number of characters.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for column when not provided. Cannot be set when column is required.', true)
            ->param('array', false, new Boolean(), 'Is column an array?', true)
            ->param('encrypt', false, new Boolean(), 'Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('plan')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
