<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\IndexDependency as IndexDependencyValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    public static function getName(): string
    {
        return 'deleteAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_NONE;
    }

    public function __construct()
    {
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
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/delete-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_NOCONTENT,
                        model: UtopiaResponse::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.deleteColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        $attribute = $dbForProject->getDocument('attributes', $db->getSequence() . '_' . $collection->getSequence() . '_' . $key);
        if ($attribute->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$key]);
        }

        $validator = new IndexDependencyValidator(
            $collection->getAttribute('indexes'),
            $dbForProject->getAdapter()->getSupportForCastIndexArray(),
        );

        if (!$validator->isValid($attribute)) {
            throw new Exception($this->getIndexDependencyException(), params: [$key]);
        }

        if ($attribute->getAttribute('status') === 'available') {
            $attribute = $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);
        $dbForProject->purgeCachedCollection('database_' . $db->getSequence() . '_collection_' . $collection->getSequence());

        if ($attribute->getAttribute('type') === Database::VAR_RELATIONSHIP) {
            $options = $attribute->getAttribute('options');
            if ($options['twoWay']) {
                $relatedCollection = $dbForProject->getDocument('database_' . $db->getSequence(), $options['relatedCollection']);
                if ($relatedCollection->isEmpty()) {
                    throw new Exception($this->getParentNotFoundException(), params: [$options['relatedCollection']]);
                }

                $relatedAttribute = $dbForProject->getDocument('attributes', $db->getSequence() . '_' . $relatedCollection->getSequence() . '_' . $options['twoWayKey']);
                if ($relatedAttribute->isEmpty()) {
                    throw new Exception($this->getNotFoundException(), params: [$options['twoWayKey']]);
                }

                if ($relatedAttribute->getAttribute('status') === 'available') {
                    $dbForProject->updateDocument('attributes', $relatedAttribute->getId(), $relatedAttribute->setAttribute('status', 'deleting'));
                }

                $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $options['relatedCollection']);
                $dbForProject->purgeCachedCollection('database_' . $db->getSequence() . '_collection_' . $relatedCollection->getSequence());
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

        $model = $this->getModel($type, $format);

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setParam('columnId', $attribute->getId())
            ->setPayload($response->output($attribute, $model))
            ->setContext($this->getCollectionsEventsContext(), $collection);

        $response->noContent();
    }
}
