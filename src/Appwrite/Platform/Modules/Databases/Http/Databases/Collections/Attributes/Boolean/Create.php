<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Boolean;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createBooleanAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_BOOLEAN;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/boolean')
            ->desc('Create boolean attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-boolean-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                deprecated: [
                    'version' => '1.8.0',
                    'replaceWith' => 'tables.createBooleanColumn',
                ],
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Boolean(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, ?bool $required, ?bool $default, bool $array, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): void
    {
        $attribute = $this->createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
