<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Integer;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Integer\Update as IntegerUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;

class Update extends IntegerUpdate
{
    public static function getName(): string
    {
        return 'updateIntegerColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_INTEGER;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/integer/:key')
            ->desc('Update integer attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', ['collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'documentsdb.[databaseId].collections.[collectionId].attributes.[attributeId].update')
            ->label('audits.event', 'attribute.update')
            ->label('audits.resource', 'documentsdb/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: "documentsDB",
                group: "attributes",
                name: self::getName(),
                description: '/docs/references/documentsdb/update-integer-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('min', null, new Integer(), 'Minimum value', true)
            ->param('max', null, new Integer(), 'Maximum value', true)
            ->param('default', null, new Nullable(new Integer()), 'Default value. Cannot be set when attribute is required.')
            ->param('newKey', null, new Key(), 'New Attribute Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
