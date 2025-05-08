<?php

namespace Appwrite\Platform\Modules\Databases\Http\Indexes;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
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
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createIndex';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/indexes')
            ->desc('Create index')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].tables.[tableId].indexes.[indexId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'index.create')
            // TODO: audits table or collections, check the context type if possible, move into another module.
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'tables',
                name: 'createIndex',
                description: '/docs/references/databases/create-index.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: UtopiaResponse::MODEL_INDEX,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', null, new Key(), 'Index Key.')
            ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE]), 'Index type.')
            ->param('columns', null, new ArrayList(new Key(true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of columns to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' columns are allowed, each 32 characters long.')
            ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $key, string $type, array $columns, array $orders, UtopiaResponse $response, Database $dbForProject, EventDatabase $queueForDatabase, Event $queueForEvents): void
    {
        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);

        if ($table->isEmpty()) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        $count = $dbForProject->count('indexes', [
            Query::equal('collectionInternalId', [$table->getInternalId()]),
            Query::equal('databaseInternalId', [$db->getInternalId()])
        ], 61);

        $limit = $dbForProject->getLimitForIndexes();

        if ($count >= $limit) {
            throw new Exception(Exception::INDEX_LIMIT_EXCEEDED, 'Index limit exceeded');
        }

        // Convert Document[] to array of attribute metadata
        $oldColumns = \array_map(fn ($a) => $a->getArrayCopy(), $table->getAttribute('attributes'));

        $oldColumns[] = [
            'key' => '$id',
            'type' => Database::VAR_STRING,
            'status' => 'available',
            'required' => true,
            'array' => false,
            'default' => null,
            'size' => Database::LENGTH_KEY
        ];

        $oldColumns[] = [
            'key' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        $oldColumns[] = [
            'key' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'status' => 'available',
            'signed' => false,
            'required' => false,
            'array' => false,
            'default' => null,
            'size' => 0
        ];

        // lengths hidden by default
        $lengths = [];

        foreach ($columns as $i => $column) {
            // find attribute metadata in collection document
            $columnIndex = \array_search($column, array_column($oldColumns, 'key'));

            if ($columnIndex === false) {
                throw new Exception(Exception::COLUMN_UNKNOWN, 'Unknown column: ' . $column . '. Verify the column name or create the column.');
            }

            $columnStatus = $oldColumns[$columnIndex]['status'];
            $columnType = $oldColumns[$columnIndex]['type'];
            $columnArray = $oldColumns[$columnIndex]['array'] ?? false;

            if ($columnType === Database::VAR_RELATIONSHIP) {
                throw new Exception(Exception::COLUMN_TYPE_INVALID, 'Cannot create an index for a relationship column: ' . $oldColumns[$columnIndex]['key']);
            }

            // ensure attribute is available
            if ($columnStatus !== 'available') {
                throw new Exception(Exception::COLUMN_NOT_AVAILABLE, 'Column not available: ' . $oldColumns[$columnIndex]['key']);
            }

            $lengths[$i] = null;

            if ($columnArray === true) {
                $lengths[$i] = Database::ARRAY_INDEX_LENGTH;
                $orders[$i] = null;
            }
        }

        $index = new Document([
            '$id' => ID::custom($db->getInternalId() . '_' . $table->getInternalId() . '_' . $key),
            'key' => $key,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'databaseInternalId' => $db->getInternalId(),
            'databaseId' => $databaseId,
            'collectionInternalId' => $table->getInternalId(),
            'collectionId' => $tableId,
            'type' => $type,
            'attributes' => $columns,
            'lengths' => $lengths,
            'orders' => $orders,
        ]);

        $validator = new IndexValidator(
            $table->getAttribute('attributes'),
            $dbForProject->getAdapter()->getMaxIndexLength(),
            $dbForProject->getAdapter()->getInternalIndexesKeys(),
        );
        if (!$validator->isValid($index)) {
            throw new Exception(Exception::INDEX_INVALID, $validator->getDescription());
        }

        try {
            $index = $dbForProject->createDocument('indexes', $index);
        } catch (DuplicateException) {
            throw new Exception(Exception::INDEX_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);

        $queueForDatabase
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setDatabase($db)
            ->setTable($table)
            ->setRow($index);

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('indexId', $index->getId())
            ->setContext('table', $table)
            ->setContext('database', $db);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($index, UtopiaResponse::MODEL_INDEX);
    }
}
