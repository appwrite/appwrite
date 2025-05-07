<?php

namespace Appwrite\Platform\Modules\Databases\Http\Attributes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\IndexDependency as IndexDependencyValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteAttribute';
    }

    public function __construct()
    {
        // we should correctly & carefully set the context later.
        $this->setResponseModel(UtopiaResponse::MODEL_NONE);

        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
            ->desc('Delete attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].update')
            ->label('audits.event', 'attribute.delete')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'attributes',
                name: 'deleteAttribute',
                description: '/docs/references/databases/delete-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(
        string         $databaseId,
        string         $collectionId,
        string         $key,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents
    ): void {
        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getInternalId(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException());
        }

        $attribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);
        if ($attribute->isEmpty()) {
            throw new Exception($this->getNotFoundException());
        }

        $validator = new IndexDependencyValidator(
            $collection->getAttribute('indexes'),
            $dbForProject->getAdapter()->getSupportForCastIndexArray(),
        );
        if (!$validator->isValid($attribute)) {
            throw new Exception(Exception::INDEX_DEPENDENCY);
        }

        if ($attribute->getAttribute('status') === 'available') {
            $attribute = $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $collection->getInternalId());

        if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
            $options = $attribute->getAttribute('options');
            if ($options['twoWay']) {
                $relatedCollection = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection']);
                if ($relatedCollection->isEmpty()) {
                    throw new Exception($this->getParentNotFoundException());
                }

                $relatedAttribute = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedCollection->getInternalId() . '_' . $options['twoWayKey']);
                if ($relatedAttribute->isEmpty()) {
                    throw new Exception($this->getNotFoundException());
                }

                if ($relatedAttribute->getAttribute('status') === 'available') {
                    $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'deleting'));
                }

                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $options['relatedCollection']);
                $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedCollection->getInternalId());
            }
        }

        $queueForDatabase
            ->setDatabase($db)
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE);

        if ($this->isCollectionsAPI()) {
            $queueForDatabase
                ->setRow($attribute)
                ->setTable($collection);
        } else {
            $queueForDatabase
                ->setDocument($attribute)
                ->setCollection($collection);
        }

        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = $this->getCorrectModel($type, $format);

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setPayload($response->output($attribute, $model))
            ->setParam($this->getEventsParamKey(), $attribute->getId())
            // tableId or columnId
            ->setParam($this->getParentEventsParamKey(), $collection->getId())
            // set proper context
            ->setContext($this->isCollectionsAPI() ? 'collection' : 'table', $collection);

        $response->noContent();
    }
}
