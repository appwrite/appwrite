<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns;

use Appwrite\Platform\Modules\Databases\Http\Attributes\Get as AttributesGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
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
        $this->setContext(DATABASE_COLUMNS_CONTEXT);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/:key')
            ->desc('Get column')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-attribute.md',
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
            ->callback(function (string $databaseId, string $tableId, string $key, UtopiaResponse $response, Database $dbForProject) {
                parent::action($databaseId, $tableId, $key, $response, $dbForProject);
            });
    }
}
