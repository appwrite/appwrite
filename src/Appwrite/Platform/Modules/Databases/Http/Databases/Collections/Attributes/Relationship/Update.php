<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship;

use Appwrite\Event\Event;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    public static function getName(): string
    {
        return 'updateRelationshipAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_RELATIONSHIP;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/:key/relationship')
            ->desc('Update relationship attribute')
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
                description: '/docs/references/databases/update-relationship-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.updateRelationshipColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->param('onDelete', null, new Nullable(new WhiteList([
                Database::RELATION_MUTATE_CASCADE,
                Database::RELATION_MUTATE_RESTRICT,
                Database::RELATION_MUTATE_SET_NULL
            ], true)), 'Constraints option', true)
            ->param('newKey', null, new Nullable(new Key()), 'New Attribute Key.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string         $databaseId,
        string         $collectionId,
        string         $key,
        ?string        $onDelete,
        ?string        $newKey,
        UtopiaResponse $response,
        Database       $dbForProject,
        Event          $queueForEvents,
        Authorization  $authorization
    ): void {
        $attribute = $this->updateAttribute(
            databaseId: $databaseId,
            collectionId: $collectionId,
            key: $key,
            dbForProject: $dbForProject,
            queueForEvents: $queueForEvents,
            authorization: $authorization,
            type: Database::VAR_RELATIONSHIP,
            required: false,
            options: [
                'onDelete' => $onDelete
            ],
            newKey: $newKey
        );

        foreach ($attribute->getAttribute('options', []) as $k => $option) {
            $attribute->setAttribute($k, $option);
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
