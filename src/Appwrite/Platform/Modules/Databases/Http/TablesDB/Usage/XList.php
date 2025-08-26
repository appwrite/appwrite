<?php

namespace Appwrite\Platform\Modules\Databases\Http\TablesDB\Usage;

use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\XList as DatabaseUsageXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class XList extends DatabaseUsageXList
{
    public static function getName(): string
    {
        return 'listTablesDBUsage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/tablesdb/usage')
            ->desc('Get TablesDB usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'tables.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'tablesDB',
                    group: null,
                    name: 'listUsage',
                    description: '/docs/references/tablesdb/list-usage.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_USAGE_DATABASES,
                        )
                    ],
                    contentType: ContentType::JSON
                ),
            ])
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
