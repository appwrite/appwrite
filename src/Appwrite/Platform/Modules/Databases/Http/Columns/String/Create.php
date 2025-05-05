<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns\String;

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
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends ColumnAction
{
    use HTTP;

    public static function getName(): string
    {
        return 'createStringColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/string')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/string')
            ->desc('Create string column')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].tables.[tableId].columns.[columnId].create')
            ->label('audits.event', 'column.create')
            ->label('audits.resource', 'database/{request.databaseId}/table/{request.tableId}')
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'createStringColumn',
                description: '/docs/references/databases/create-string-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: UtopiaResponse::MODEL_COLUMN_STRING
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', '', new Key(), 'Column Key.')
            ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Validator::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
            ->param('required', null, new Boolean(), 'Is column required?')
            ->param('default', null, new Text(0, 0), 'Default value for column when not provided. Cannot be set when column is required.', true)
            ->param('array', false, new Boolean(), 'Is column an array?', true)
            ->param('encrypt', false, new Boolean(), 'Toggle encryption for the column. Encryption enhances security by not storing any plain text values in the database. However, encrypted columns cannot be queried.', true)
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
        ?int           $size,
        ?bool          $required,
        ?string        $default,
        bool           $array,
        bool           $encrypt,
        UtopiaResponse $response,
        Database       $dbForProject,
        EventDatabase  $queueForDatabase,
        Event          $queueForEvents
    ): void {
        // Ensure default fits in the given size
        $validator = new Text($size, 0);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception(Exception::ATTRIBUTE_VALUE_INVALID, $validator->getDescription());
        }

        $filters = [];
        if ($encrypt) {
            $filters[] = 'encrypt';
        }

        $column = $this->createColumn(
            $databaseId,
            $tableId,
            new Document([
                'key' => $key,
                'type' => Database::VAR_STRING,
                'size' => $size,
                'required' => $required,
                'default' => $default,
                'array' => $array,
                'filters' => $filters,
            ]),
            $response,
            $dbForProject,
            $queueForDatabase,
            $queueForEvents
        );

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($column, UtopiaResponse::MODEL_COLUMN_STRING);
    }
}
