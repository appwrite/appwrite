<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables\Columns;

use Appwrite\Platform\Modules\Databases\Context;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Get as AttributesGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends AttributesGet
{
    use HTTP;

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
        $this->setContext(Context::DATABASE_COLUMNS);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/:key')
            ->desc('Get column')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-column.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
