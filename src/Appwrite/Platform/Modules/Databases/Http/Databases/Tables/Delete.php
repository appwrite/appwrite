<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Tables;

use Appwrite\Platform\Modules\Databases\Context;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Delete as CollectionDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends CollectionDelete
{
    use HTTP;

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
        $this->setContext(Context::DATABASE_TABLES);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId')
            ->desc('Delete table')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].delete')
            ->label('audits.event', 'table.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-table.md',
                auth: [AuthType::KEY],
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
            ->callback($this->action(...));
    }
}
