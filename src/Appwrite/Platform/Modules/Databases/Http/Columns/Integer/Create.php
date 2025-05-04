<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns\Integer;

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
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;

class Create extends ColumnAction
{
    use HTTP;

    public static function getName(): string
    {
        return 'createIntegerColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/integer')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/integer')
            ->desc('Create integer column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'createIntegerColumn',
                description: '/docs/references/databases/create-integer-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: UtopiaResponse::MODEL_ATTRIBUTE_INTEGER,
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('min', null, new Integer(), 'Minimum value', true)
            ->param('max', null, new Integer(), 'Maximum value', true)
            ->param('default', null, new Integer(), 'Default value. Cannot be set when column is required.', true)
            ->param('array', false, new Boolean(), 'Is column an array?', true)
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
        ?bool          $required,
        ?int           $min,
        ?int           $max,
        ?int           $default,
        bool           $array,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents
    ): void {
        $min ??= \PHP_INT_MIN;
        $max ??= \PHP_INT_MAX;

        if ($min > $max) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Database::VAR_INTEGER);
        if (!\is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $size = $max > 2147483647 ? 8 : 4;

        $column = $this->createColumn($databaseId, $tableId, new Document([
            'key' => $key,
            'type' => Database::VAR_INTEGER,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
            'formatOptions' => ['min' => $min, 'max' => $max],
        ]), $response, $dbForProject, $queueForDatabase, $queueForEvents);

        $formatOptions = $column->getAttribute('formatOptions', []);
        if (!empty($formatOptions)) {
            $column->setAttribute('min', \intval($formatOptions['min']));
            $column->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($column, UtopiaResponse::MODEL_ATTRIBUTE_INTEGER);
    }
}
