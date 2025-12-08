<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Usage;

use Appwrite\Platform\Modules\Databases\Http\Databases\Usage\Get as DatabaseUsageGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class Get extends DatabaseUsageGet
{
    public static function getName(): string
    {
        return 'getDocumentsDBUsage';
    }

    public function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_USAGE_DOCUMENTSDB;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/documentsdb/:databaseId/usage')
            ->desc('Get DocumentsDB usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', [
                new Method(
                    namespace: 'documentsDB',
                    group: null,
                    name: 'getUsage',
                    description: '/docs/references/documentsdb/get-database-usage.md',
                    auth: [AuthType::ADMIN],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: UtopiaResponse::MODEL_USAGE_DOCUMENTSDB,
                        )
                    ],
                    contentType: ContentType::JSON,
                ),
            ])
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
