<?php

namespace Appwrite\Platform\Modules\Databases\Http\Indexes;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
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
        return 'getIndex';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/tables/:tableId/indexes/:key')
            ->httpAlias('/v1/databases/:databaseId/collections/:tableId/indexes/:key')
            ->desc('Get index')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: 'indexes',
                name: 'getIndex',
                description: '/docs/references/databases/get-index.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_INDEX,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('tableId', '', new UID(), 'Table ID. You can create a new table using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', null, new Key(), 'Index Key.')
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
            throw new Exception(Exception::COLLECTION_NOT_FOUND);
        }

        $index = $table->find('key', $key, 'indexes');
        if (empty($index)) {
            throw new Exception(Exception::INDEX_NOT_FOUND);
        }

        $response->dynamic($index, UtopiaResponse::MODEL_INDEX);
    }
}
