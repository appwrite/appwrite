<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Tables;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Delete as CollectionDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends CollectionDelete
{
    public static function getName(): string
    {
        return 'deleteTable';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_TABLE;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/tablesdb/:databaseId/tables/:tableId')
            ->desc('Delete table')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', ['tables.write', 'collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].delete')
            ->label('audits.event', 'table.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: 'tables',
                name: self::getName(),
                description: '/docs/references/tablesdb/delete-table.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }
}
