<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns\Float;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Float\Update as FloatUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Nullable;

class Update extends FloatUpdate
{
    public static function getName(): string
    {
        return 'updateFloatColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_COLUMN_FLOAT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/columns/float/:key')
            ->desc('Update float column')
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
                description: '/docs/references/tablesdb/update-float-column.md',
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
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('min', null, new Nullable(new FloatValidator()), 'Minimum value', true)
            ->param('max', null, new Nullable(new FloatValidator()), 'Maximum value', true)
            ->param('default', null, new Nullable(new FloatValidator()), 'Default value. Cannot be set when required.')
            ->param('newKey', null, new Nullable(new Key()), 'New Column Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
