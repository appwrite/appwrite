<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorsDB\Usage;

use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\XList as DatabaseUsageXList;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class XList extends DatabaseUsageXList
{
    public static function getName(): string
    {
        return 'listVectorsDBUsage';
    }

    public function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_USAGE_VECTORSDBS;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/vectorsdb/usage')
            ->desc('Get VectorsDB usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'vectorsDB',
                    group: null,
                    name: 'listUsage',
                    description: '/docs/references/vectorsdb/list-usage.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_USAGE_VECTORSDBS,
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
