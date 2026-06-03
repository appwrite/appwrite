<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorsDB\Logs;

use Appwrite\Platform\Modules\Databases\Http\Databases\Logs\XList as DatabaseLogs;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Types;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;

class XList extends DatabaseLogs
{
    public static function getName(): string
    {
        return 'listVectorsDBLogs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vectorsdb/:databaseId/logs')
            ->desc('List database logs')
            ->groups(['api', 'database'])
            ->label('scope', 'databases.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'vectorsDB',
                    group: 'logs',
                    name: 'listDatabaseLogs',
                    description: '/docs/references/vectorsdb/get-logs.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_LOG_LIST,
                        )
                    ],
                    contentType: ContentType::JSON
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('queries', [], new Types([Query::TYPE_LIMIT, Query::TYPE_OFFSET]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('geodb')
            ->inject('authorization')
            ->inject('audit')
            ->callback($this->action(...));
    }
}
