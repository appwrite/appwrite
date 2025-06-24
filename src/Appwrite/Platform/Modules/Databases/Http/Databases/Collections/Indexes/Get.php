<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Indexes;

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
use Utopia\Swoole\Response as SwooleResponse;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getIndex';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_INDEX;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/indexes/:key')
            ->desc('Get index')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSdkNamespace(),
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-index.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: [
                    'replaceWith' => 'tables.getColumnIndex',
                ],
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/databases#databasesCreateCollection).')
            ->param('key', null, new Key(), 'Index Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, UtopiaResponse $response, Database $dbForProject): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);

        if ($collection->isEmpty()) {
            // table or collection.
            throw new Exception($this->getGrandParentNotFoundException());
        }

        $index = $collection->find('key', $key, 'indexes');
        if (empty($index)) {
            throw new Exception($this->getNotFoundException());
        }

        $response->dynamic($index, $this->getResponseModel());
    }
}
