<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Point;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Point\Create as PointCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Spatial;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;

class Create extends PointCreate
{
    public static function getName(): string
    {
        return 'createPointColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_POINT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/point')
            ->desc('Create point attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('event', 'documentsdb.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('scope', ['collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'documentsdb/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: "documentsDB",
                group: "attributes",
                name: self::getName(),
                description: '/docs/references/documentsdb/create-point-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the DocumentsDB service [server integration](https://appwrite.io/docs/server/documentsdb#documentsDBCreate).')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Nullable(new Spatial(Database::VAR_POINT)), 'Default value for attribute when not provided, array of two numbers [longitude, latitude], representing a single coordinate. Cannot be set when attribute is required.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
