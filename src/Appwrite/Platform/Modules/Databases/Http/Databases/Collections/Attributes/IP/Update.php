<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\IP;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\IP;
use Utopia\Validator\Nullable;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateIpAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_IP;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/ip/:key')
            ->desc('Update IP address attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
            ->label('audits.event', 'attribute.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-ip-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'grids.updateIpColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Nullable(new IP()), 'Default value. Cannot be set when attribute is required.')
            ->param('newKey', null, new Key(), 'New Attribute Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, ?bool $required, ?string $default, ?string $newKey, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents): void
    {
        $attribute = $this->updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_STRING,
            filter: APP_DATABASE_ATTRIBUTE_IP,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
