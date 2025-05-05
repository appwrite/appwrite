<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns\Enum;

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
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Create extends ColumnAction
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEnumColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/enum')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/enum')
            ->desc('Create enum column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'createEnumColumn',
                description: '/docs/references/databases/create-attribute-enum.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: UtopiaResponse::MODEL_COLUMN_ENUM,
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('elements', [], new ArrayList(new Text(Database::LENGTH_KEY), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of enum values.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, new Text(0), 'Default value for column when not provided. Cannot be set when column is required.', true)
            ->param('array', false, new Boolean(), 'Is column an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForDatabase')
            ->inject('queueForEvents')
            ->callback([$this, 'action']);
    }

    public function action(
        string                    $databaseId,
        string                    $tableId,
        string                    $key,
        array                     $elements,
        ?bool                     $required,
        ?string                   $default,
        bool                      $array,
        \Appwrite\Utopia\Response $response,
        Database                  $dbForProject,
        EventDatabase             $queueForDatabase,
        Event                     $queueForEvents
    ): void {
        if (!is_null($default) && !in_array($default, $elements, true)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, 'Default value not found in elements');
        }

        $column = $this->createColumn(
            $databaseId,
            $tableId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_STRING,
                'size' => Database::LENGTH_KEY,
                'required' => $required,
                'default' => $default,
                'array' => $array,
                'format' => APP_DATABASE_ATTRIBUTE_ENUM,
                'formatOptions' => ['elements' => $elements],
            ]),
            $response,
            $dbForProject,
            $queueForDatabase,
            $queueForEvents
        );

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($column, UtopiaResponse::MODEL_COLUMN_ENUM);
    }
}
