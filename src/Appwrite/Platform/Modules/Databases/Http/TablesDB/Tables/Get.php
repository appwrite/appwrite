<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Get as CollectionGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends CollectionGet
{
    public static function getName(): string
    {
        return 'getTable';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TABLE;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId')
            ->desc('Get table')
            ->groups(['api', 'database'])
            ->label('scope', ['tables.read', 'collections.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: null,
                name: self::getName(),
                description: '/docs/references/tablesdb/get-table.md',
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
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
