<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables\Columns;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Get as AttributesGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;

class Get extends AttributesGet
{
    public static function getName(): string
    {
        return 'getColumn';
    }

    protected function getResponseModel(): string|array
    {
        return [
            UtopiaResponse::MODEL_COLUMN_BOOLEAN,
            UtopiaResponse::MODEL_COLUMN_INTEGER,
            UtopiaResponse::MODEL_COLUMN_FLOAT,
            UtopiaResponse::MODEL_COLUMN_EMAIL,
            UtopiaResponse::MODEL_COLUMN_ENUM,
            UtopiaResponse::MODEL_COLUMN_URL,
            UtopiaResponse::MODEL_COLUMN_IP,
            UtopiaResponse::MODEL_COLUMN_DATETIME,
            UtopiaResponse::MODEL_COLUMN_RELATIONSHIP,
            UtopiaResponse::MODEL_COLUMN_STRING,
        ];
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId/columns/:key')
            ->desc('Get column')
            ->groups(['api', 'database'])
            ->label('scope', ['tables.read', 'collections.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/tablesdb/get-column.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('tableId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Table ID.', false, ['dbForProject'])
            ->param('key', '', fn (Database $dbForProject) => new Key(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Column Key.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
