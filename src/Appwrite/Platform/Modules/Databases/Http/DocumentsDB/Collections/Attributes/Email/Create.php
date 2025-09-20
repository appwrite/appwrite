<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Email;

use Appwrite\Network\Validator\Email;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Email\Create as EmailCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class Create extends EmailCreate
{
    public static function getName(): string
    {
        return 'createEmailColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_EMAIL;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/email')
            ->desc('Create email attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', ['collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'documentsdb.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'documentsdb/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: "documentsDB",
                group: "attributes",
                name: self::getName(),
                description: '/docs/references/documentsdb/create-email-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Email(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
