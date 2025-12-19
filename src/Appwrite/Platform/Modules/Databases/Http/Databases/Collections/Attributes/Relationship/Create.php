<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createRelationshipAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_RELATIONSHIP;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/relationship')
            ->desc('Create relationship attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-relationship-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel()
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createRelationshipColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('relatedCollectionId', '', new UID(), 'Related Collection ID.')
            ->param('type', '', new WhiteList([
                Database::RELATION_ONE_TO_ONE,
                Database::RELATION_MANY_TO_ONE,
                Database::RELATION_MANY_TO_MANY,
                Database::RELATION_ONE_TO_MANY
            ], true), 'Relation type')
            ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
            ->param('key', null, new Nullable(new Key()), 'Attribute Key.', true)
            ->param('twoWayKey', null, new Nullable(new Key()), 'Two Way Attribute Key.', true)
            ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([
                Database::RELATION_MUTATE_CASCADE,
                Database::RELATION_MUTATE_RESTRICT,
                Database::RELATION_MUTATE_SET_NULL
            ], true), 'Constraints option', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $relatedCollectionId, string $type, bool $twoWay, ?string $key, ?string $twoWayKey, string $onDelete, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $key ??= $relatedCollectionId;
        $twoWayKeyWasProvided = $twoWayKey !== null;
        $twoWayKey ??= $collectionId;

        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        $collection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $collection->getSequence());
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        $relatedCollectionDocument = $dbForProject->getDocument('database_' . $database->getSequence(), $relatedCollectionId);
        $relatedCollection = $dbForProject->getCollection('database_' . $database->getSequence() . '_collection_' . $relatedCollectionDocument->getSequence());
        if ($relatedCollection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$relatedCollectionId]);
        }

        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            if ($attribute->getAttribute('type') !== Database::VAR_RELATIONSHIP) {
                continue;
            }

            if (\strtolower($attribute->getId()) === \strtolower($key)) {
                throw new Exception($this->getDuplicateException(), params: [$key]);
            }

            if (
                \strtolower($attribute->getAttribute('options')['twoWayKey']) === \strtolower($twoWayKey) &&
                $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                // If user explicitly provided twoWayKey, report that.
                // Otherwise report the key that they're trying to create.
                $conflictingKey = $twoWayKeyWasProvided ? $twoWayKey : $key;
                throw new Exception($this->getDuplicateException(), params: [$conflictingKey]);
            }

            if (
                $type === Database::RELATION_MANY_TO_MANY &&
                $attribute->getAttribute('options')['relationType'] === Database::RELATION_MANY_TO_MANY &&
                $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                $parentType = $this->isCollectionsAPI() ? 'collection' : 'table';
                throw new Exception($this->getDuplicateException(), "Creating more than one \"manyToMany\" relationship on the same $parentType is currently not permitted.");
            }
        }

        $attribute = $this->createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_RELATIONSHIP,
            'size' => 0,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
            'options' => [
                'relatedCollection' => $relatedCollectionId,
                'relationType' => $type,
                'twoWay' => $twoWay,
                'twoWayKey' => $twoWayKey,
                'onDelete' => $onDelete,
            ]
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents, $authorization);

        foreach ($attribute->getAttribute('options', []) as $k => $option) {
            $attribute->setAttribute($k, $option);
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
