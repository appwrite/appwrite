<?php

namespace Appwrite\Platform\Modules\Databases\Http\DocumentsDB\Collections\Attributes\Relationship;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Create as RelationshipCreate;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class Create extends RelationshipCreate
{
    public static function getName(): string
    {
        return 'createRelationshipColumn';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_RELATIONSHIP;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/documentsdb/:databaseId/collections/:collectionId/attributes/relationship')
            ->desc('Create relationship attribute')
            ->groups(['api', 'database'])
            ->label('scope', ['collections.write'])
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'documentsdb.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'documentsdb/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: "documentsDB",
                group: "attributes",
                name: self::getName(),
                description: '/docs/references/documentsdb/create-relationship-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel()
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('relatedcollectionId', '', new UID(), 'Related Collection ID.')
            ->param('type', '', new WhiteList([
                Database::RELATION_ONE_TO_ONE,
                Database::RELATION_MANY_TO_ONE,
                Database::RELATION_MANY_TO_MANY,
                Database::RELATION_ONE_TO_MANY
            ], true), 'Relation type')
            ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
            ->param('key', null, new Key(), 'Attribute Key.', true)
            ->param('twoWayKey', null, new Key(), 'Two Way Attribute Key.', true)
            ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([
                Database::RELATION_MUTATE_CASCADE,
                Database::RELATION_MUTATE_RESTRICT,
                Database::RELATION_MUTATE_SET_NULL
            ], true), 'Constraints option', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }
}
