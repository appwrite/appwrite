<?php

namespace Appwrite\Platform\Modules\Databases\Http\Columns;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getColumn';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/columns/:key')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/attributes/:key')
            ->desc('Get column')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'columns',
                name: 'getColumn',
                description: '/docs/references/databases/get-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: [
                            UtopiaResponse::MODEL_COLUMN_BOOLEAN,
                            UtopiaResponse::MODEL_COLUMN_INTEGER,
                            UtopiaResponse::MODEL_COLUMN_FLOAT,
                            UtopiaResponse::MODEL_COLUMN_EMAIL,
                            UtopiaResponse::MODEL_COLUMN_ENUM,
                            UtopiaResponse::MODEL_COLUMN_URL,
                            UtopiaResponse::MODEL_COLUMN_IP,
                            UtopiaResponse::MODEL_COLUMN_DATETIME,
                            UtopiaResponse::MODEL_COLUMN_RELATIONSHIP,
                            UtopiaResponse::MODEL_COLUMN_STRING,
                        ]
                    )
                ]
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID.')
            ->param('key', '', new Key(), 'Column Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $databaseId, string $tableId, string $key, UtopiaResponse $response, Database $dbForProject): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $table = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        if ($table->isEmpty()) {
            throw new Exception(Exception::TABLE_NOT_FOUND);
        }

        $column = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $table->getInternalId() . '_' . $key);
        if ($column->isEmpty()) {
            throw new Exception(Exception::ATTRIBUTE_NOT_FOUND);
        }

        $type = $column->getAttribute('type');
        $format = $column->getAttribute('format');
        $options = $column->getAttribute('options', []);

        foreach ($options as $optKey => $optValue) {
            $column->setAttribute($optKey, $optValue);
        }

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

        $response->dynamic($column, $model);
    }
}
