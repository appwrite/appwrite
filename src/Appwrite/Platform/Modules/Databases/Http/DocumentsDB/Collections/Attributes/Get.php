<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Get as AttributesGet;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends AttributesGet
{
    public static function getName(): string
    {
        return 'getAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return [
            UtopiaResponse::MODEL_ATTRIBUTE_BOOLEAN,
            UtopiaResponse::MODEL_ATTRIBUTE_INTEGER,
            UtopiaResponse::MODEL_ATTRIBUTE_FLOAT,
            UtopiaResponse::MODEL_ATTRIBUTE_EMAIL,
            UtopiaResponse::MODEL_ATTRIBUTE_ENUM,
            UtopiaResponse::MODEL_ATTRIBUTE_URL,
            UtopiaResponse::MODEL_ATTRIBUTE_IP,
            UtopiaResponse::MODEL_ATTRIBUTE_DATETIME,
            UtopiaResponse::MODEL_ATTRIBUTE_RELATIONSHIP,
            UtopiaResponse::MODEL_ATTRIBUTE_STRING,
        ];
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/:key')
            ->desc('Get attribute')
            ->groups(['api', 'database'])
            ->label('scope', ['collections.read'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: "documentsDB",
                group: "attributes",
                name: self::getName(),
                description: '/docs/references/documentsdb/get-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }
}
