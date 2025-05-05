<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns\Relationship;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Columns\Action as ColumnAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class Create extends ColumnAction
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRelationshipColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/relationship')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/relationship')
            ->desc('Create relationship column')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'createRelationshipColumn',
                description: '/docs/references/databases/create-relationship-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: UtopiaResponse::MODEL_COLUMN_RELATIONSHIP
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('relatedTableId', '', new UID(), 'Related Table ID.')
            ->param('type', '', new WhiteList([
                Database::RELATION_ONE_TO_ONE,
                Database::RELATION_MANY_TO_ONE,
                Database::RELATION_MANY_TO_MANY,
                Database::RELATION_ONE_TO_MANY
            ], true), 'Relation type')
            ->param('twoWay', false, new Boolean(), 'Is Two Way?', true)
            ->param('key', null, new Key(), 'Column Key.', true)
            ->param('twoWayKey', null, new Key(), 'Two Way Column Key.', true)
            ->param('onDelete', Database::RELATION_MUTATE_RESTRICT, new WhiteList([
                Database::RELATION_MUTATE_CASCADE,
                Database::RELATION_MUTATE_RESTRICT,
                Database::RELATION_MUTATE_SET_NULL
            ], true), 'Constraints option', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(
        string         $databaseId,
        string         $tableId,
        string         $relatedTableId,
        string         $type,
        bool           $twoWay,
        ?string        $key,
        ?string        $twoWayKey,
        string         $onDelete,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents
    ): void {
        $key ??= $relatedTableId;
        $twoWayKey ??= $tableId;

        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        $table = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $table->getInternalId());
        if ($table->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $relatedTableDocument = $dbForProject->getDocument('database_' . $database->getInternalId(), $relatedTableId);
        $relatedTable = $dbForProject->getCollection('database_' . $database->getInternalId() . '_collection_' . $relatedTableDocument->getInternalId());
        if ($relatedTable->isEmpty()) {
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $columns = $table->getAttribute('attributes', []);
        foreach ($columns as $column) {
            if ($column->getAttribute('type') !== Database::VAR_RELATIONSHIP) {
                continue;
            }

            if (\strtolower($column->getId()) === \strtolower($key)) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS);
            }

            if (
                \strtolower($column->getAttribute('options')['twoWayKey']) === \strtolower($twoWayKey) &&
                $column->getAttribute('options')['relatedCollection'] === $relatedTable->getId()
            ) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Attribute with the requested key already exists. Attribute keys must be unique, try again with a different key.');
            }

            if (
                $type === Database::RELATION_MANY_TO_MANY &&
                $column->getAttribute('options')['relationType'] === Database::RELATION_MANY_TO_MANY &&
                $column->getAttribute('options')['relatedCollection'] === $relatedTable->getId()
            ) {
                throw new Exception(Exception::ATTRIBUTE_ALREADY_EXISTS, 'Creating more than one "manyToMany" relationship on the same table is currently not permitted.');
            }
        }

        $column = $this->createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_RELATIONSHIP,
            'size' => 0,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => [],
            'options' => [
                'relatedCollection' => $relatedTableId,
                'relationType' => $type,
                'twoWay' => $twoWay,
                'twoWayKey' => $twoWayKey,
                'onDelete' => $onDelete,
            ]
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        foreach ($column->getAttribute('options', []) as $k => $option) {
            $column->setAttribute($k, $option);
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($column, UtopiaResponse::MODEL_COLUMN_RELATIONSHIP);
    }
}
