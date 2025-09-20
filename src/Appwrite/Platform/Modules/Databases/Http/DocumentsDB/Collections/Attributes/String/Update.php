<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\String;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\String\Update as StringUpdate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends StringUpdate
{
    public static function getName(): string
    {
        return 'updateStringColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_STRING;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/string/:key')
            ->desc('Update string attribute')
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
                description: '/docs/references/documentsdb/update-string-attribute.md',
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
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/documentsdb#documentsDBCreate).')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Nullable(new Text(0, 0)), 'Default value for attribute when not provided. Cannot be set when attribute is required.')
            ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Validator::TYPE_INTEGER), 'Maximum size of the string attribute.', true)
            ->param('newKey', null, new Key(), 'New Attribute Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
