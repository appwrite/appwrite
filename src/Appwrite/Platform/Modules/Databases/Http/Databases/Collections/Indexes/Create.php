<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes;

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
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Index as IndexValidator;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createIndex';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_INDEX;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/indexes')
            ->desc('Create index')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].indexes.[indexId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'index.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-index.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createIndex',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', null, new Key(), 'Index Key.')
            ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE, Database::INDEX_SPATIAL]), 'Index type.')
            ->param('attributes', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' attributes are allowed, each 32 characters long.')
            ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
            ->param('lengths', [], new ArrayList(new Nullable(new Integer()), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Length of index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE, optional: true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, string $type, array $attributes, array $orders, array $lengths, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $db = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $db->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            // table or collection.
            throw new Exception($this->getGrandParentNotFoundException(), params: [$collectionId]);
        }

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$collection->getSequence()]),
            Query::equal('databaseInternalId', [$db->getSequence()])
        ], 61);

        $limit = $dbForProject->getLimitForIndexes();

        if ($count >= $limit) {
            throw new Exception($this->getLimitException(), params: [$collectionId]);
        }

        $oldAttributes = \array_map(
            fn ($a) => $a->getArrayCopy(),
            $collection->getAttribute('attributes')
        );

        $oldAttributes[] = [
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => Database::LENGTH_KEY
        ];
        $oldAttributes[] = [
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];
        $oldAttributes[] = [
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        $contextType = $this->getParentContext();
        foreach ($attributes as $i => $attribute) {
            $attributeIndex = \array_search($attribute, array_column($oldAttributes, 'key'));

            if ($attributeIndex === false) {
                throw new Exception($this->getParentUnknownException(), params: [$attribute]);
            }

            $attributeStatus = $oldAttributes[$attributeIndex]['status'];
            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeArray = $oldAttributes[$attributeIndex]['array'] ?? false;

            if ($attributeType === Database::VAR_RELATIONSHIP) {
                throw new Exception($this->getParentInvalidTypeException(), "Cannot create an index for a relationship $contextType: " . $oldAttributes[$attributeIndex]['key']);
            }

            if ($attributeStatus !== 'available') {
                throw new Exception($this->getParentNotAvailableException(), params: [$oldAttributes[$attributeIndex]['key']]);
            }

            if (empty($lengths[$i])) {
                $lengths[$i] = null;
            }

            if ($attributeArray === true) {
                // Because of a bug in MySQL, we cannot create indexes on array attributes for now, otherwise queries break.
                throw new Exception(Exception::INDEX_INVALID, 'Creating indexes on array attributes is not currently supported.');
            }
        }

        $index = new Document([
            '$id' => ID::custom($db->getSequence() . '_' . $collection->getSequence() . '_' . $key),
            'key' => $key,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'databaseInternalId' => $db->getSequence(),
            'databaseId' => $databaseId,
            'collectionInternalId' => $collection->getSequence(),
            'collectionId' => $collectionId,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $validator = new IndexValidator(
            $collection->getAttribute('attributes'),
            $collection->getAttribute('indexes'),
            $dbForProject->getAdapter()->getMaxIndexLength(),
            $dbForProject->getAdapter()->getInternalIndexesKeys(),
            $dbForProject->getAdapter()->getSupportForIndexArray(),
            $dbForProject->getAdapter()->getSupportForSpatialIndexNull(),
            $dbForProject->getAdapter()->getSupportForSpatialIndexOrder(),
            $dbForProject->getAdapter()->getSupportForVectors(),
            $dbForProject->getAdapter()->getSupportForAttributes(),
            $dbForProject->getAdapter()->getSupportForMultipleFulltextIndexes(),
            $dbForProject->getAdapter()->getSupportForIdenticalIndexes()
        );

        if (!$validator->isValid($index)) {
            throw new Exception($this->getInvalidTypeException(), $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException(), params: [$key]);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getSequence(), $collectionId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db);

        if ($this->isCollectionsAPI()) {
            $queueForDatabase
                ->setCollection($collection)
                ->setDocument($index);
        } else {
            $queueForDatabase
                ->setTable($collection)
                ->setRow($index);
        }

        $queueForEvents
            ->setContext('database', $db)
            ->setParam('databaseId', $databaseId)
            ->setParam('indexId', $index->getId())
            ->setParam('collectionId', $collection->getId())
            ->setParam('tableId', $collection->getId())
            ->setContext($this->getCollectionsEventsContext(), $collection);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($index, $this->getResponseModel());
    }
}
