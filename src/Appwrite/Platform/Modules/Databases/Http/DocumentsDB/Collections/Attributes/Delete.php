<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Delete as AttributesDelete;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends AttributesDelete
{
    public static function getName(): string
    {
        return 'deleteAttribute';
    }

    // parent handles multiple model types internally
    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_NONE;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/:key')
            ->desc('Delete attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', ['collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'documentsdb.[databaseId].collections.[collectionId].attributes.[attributeId].update')
            ->label('audits.event', 'attribute.delete')
            ->label('audits.resource', 'documentsdb/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: "documentsDB",
                group: "attributes",
                name: self::getName(),
                description: '/docs/references/documentsdb/delete-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
