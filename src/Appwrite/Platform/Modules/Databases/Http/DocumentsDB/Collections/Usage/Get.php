<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Usage;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Usage\Get as CollectionUsageGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\WhiteList;

class Get extends CollectionUsageGet
{
    public static function getName(): string
    {
        return 'getDocumentsDBCollectionUsage';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_USAGE_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/usage')
            ->desc('Get collection usage stats')
            ->groups(['api', 'database', 'usage'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'documentsdb',
                group: null,
                name: self::getName(),
                description: '/docs/references/documentsdb/get-collection-usage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabaseDB')
            ->callback($this->action(...));
    }
}
