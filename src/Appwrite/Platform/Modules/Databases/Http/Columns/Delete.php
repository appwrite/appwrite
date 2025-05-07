<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns;

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
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/:key')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/:key')
            ->desc('Delete column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].update')
            ->label('audits.event', 'column.delete')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'deleteColumn',
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
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(
        string         $databaseId,
        string         $tableId,
        string         $key,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents,
    ): void {
        $db = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($db->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $db->getInternalId(), $tableId);
        if ($table->isEmpty()) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        $column = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $table->getInternalId() . '_' . $key);
        if ($column->isEmpty()) {
            throw new Exception(Exception::COLUMN_NOT_FOUND);
        }

        $validator = new IndexDependencyValidator(
            $table->getAttribute('indexes'),
            $dbForProject->getAdapter()->getSupportForCastIndexArray(),
        );
        if (!$validator->isValid($column)) {
            throw new Exception(Exception::INDEX_DEPENDENCY);
        }

        if ($column->getAttribute('status') === 'available') {
            $column = $dbForProject->updateDocument('attributes', $column->getId(), $column->setAttribute('status', 'deleting'));
        }

        $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $tableId);
        $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $table->getInternalId());

        if ($column->getAttribute('type') === Database::VAR_RELATIONSHIP) {
            $options = $column->getAttribute('options');
            if ($options['twoWay']) {
                $relatedTable = $dbForProject->getDocument('database_' . $db->getInternalId(), $options['relatedCollection']);
                if ($relatedTable->isEmpty()) {
                    throw new Exception(Exception::TABLE_NOT_FOUND);
                }

                $relatedColumn = $dbForProject->getDocument('attributes', $db->getInternalId() . '_' . $relatedTable->getInternalId() . '_' . $options['twoWayKey']);
                if ($relatedColumn->isEmpty()) {
                    throw new Exception(Exception::COLUMN_NOT_FOUND);
                }

                if ($relatedColumn->getAttribute('status') === 'available') {
                    $dbForProject->updateDocument('attributes', $relatedColumn->getId(), $relatedColumn->setAttribute('status', 'deleting'));
                }

                $dbForProject->purgeCachedDocument('database_' . $db->getInternalId(), $options['relatedCollection']);
                $dbForProject->purgeCachedCollection('database_' . $db->getInternalId() . '_collection_' . $relatedTable->getInternalId());
            }
        }

        $queueForDatabase
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setTable($table)
            ->setDatabase($db)
            ->setRow($column);

        $type = $column->getAttribute('type');
        $format = $column->getAttribute('format');

        $model = match ($type) {
            Database::VAR_BOOLEAN => UtopiaResponse::MODEL_COLUMN_BOOLEAN,
            Database::VAR_INTEGER => UtopiaResponse::MODEL_COLUMN_INTEGER,
            Database::VAR_FLOAT => UtopiaResponse::MODEL_COLUMN_FLOAT,
            Database::VAR_DATETIME => UtopiaResponse::MODEL_COLUMN_DATETIME,
            Database::VAR_RELATIONSHIP => UtopiaResponse::MODEL_COLUMN_RELATIONSHIP,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => UtopiaResponse::MODEL_COLUMN_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => UtopiaResponse::MODEL_COLUMN_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => UtopiaResponse::MODEL_COLUMN_IP,
                APP_DATABASE_ATTRIBUTE_URL => UtopiaResponse::MODEL_COLUMN_URL,
                default => UtopiaResponse::MODEL_COLUMN_STRING,
            },
            default => UtopiaResponse::MODEL_COLUMN,
        };

        $queueForEvents
            ->setParam('databaseId', $databaseId)
            ->setParam('tableId', $table->getId())
            ->setParam('columnId', $column->getId())
            ->setContext('table', $table)
            ->setContext('database', $db)
            ->setPayload($response->output($column, $model));

        $response->noContent();
    }
}
