<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Point;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
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

class Update extends Action
{
    public static function getName(): string
    {
        return 'updatePointAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_POINT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/point/:key')
            ->desc('Update point attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
            ->label('audits.event', 'attribute.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/update-point-attribute.md',
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
                    replaceWith: 'tablesDB.updatePointColumn',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#createCollection).', false, ['dbForProject'])
            ->param('key', '', fn (Database $dbForProject) => new Key(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Attribute Key.', false, ['dbForProject'])
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('default', null, new Nullable(new Spatial(Database::VAR_POINT)), 'Default value for attribute when not provided, array of two numbers [longitude, latitude], representing a single coordinate. Cannot be set when attribute is required.', true)
            ->param('newKey', null, fn (Database $dbForProject) => new Nullable(new Key(false, $dbForProject->getAdapter()->getMaxUIDLength())), 'New attribute key.', true, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, ?bool $required, ?array $default, ?string $newKey, UtopiaResponse $response, Database $dbForProject, Event $queueForEvents): void
    {
        if (!$dbForProject->getAdapter()->getSupportForSpatialAttributes()) {
            throw new Exception(Exception::GENERAL_FEATURE_UNSUPPORTED, 'Spatial columns are not supported by this database.');
        }

        $attribute = $this->updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            type: Database::VAR_POINT,
            default: $default,
            required: $required,
            newKey: $newKey
        );

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
