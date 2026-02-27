<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB;

use Appwrite\Platform\Modules\Databases\Http\Databases\Delete as DatabaseDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;

class Delete extends DatabaseDelete
{
    public static function getName(): string
    {
        return 'deleteVectorDBDatabase';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/vectordb/:databaseId')
            ->desc('Delete database')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'databases.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].delete')
            ->label('audits.event', 'database.delete')
            ->label('audits.resource', 'database/{request.databaseId}')
            ->label('sdk', new Method(
                namespace: 'vectorDB',
                group: 'vectordb',
                name: 'delete',
                description: '/docs/references/vectordb/delete.md',
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
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }
}
