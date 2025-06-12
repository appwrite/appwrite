<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Columns\Enum;

use Appwrite\Platform\Modules\Databases\Context;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Enum\Create as EnumCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends EnumCreate
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEnumColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_ENUM;
    }

    public function __construct()
    {
        $this->setContext(Context::DATABASE_COLUMNS);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/enum')
            ->desc('Create enum column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-attribute-enum.md',
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
            ->param('elements', [], new ArrayList(new Text(Database::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of enum values.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, new Text(0), 'Default value for column when not provided. Cannot be set when column is required.', true)
            ->param('array', false, new Boolean(), 'Is column an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }
}
