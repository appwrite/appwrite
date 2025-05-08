<?php

namespace Appwrite\Platform\Modules\Databases\Http\Attributes;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Response as SwooleResponse;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getColumn';
    }

    protected function getResponseModel(): string|array
    {
        return [
            UtopiaResponse::MODEL_ATTRIBUTE_BOOLEAN,
            UtopiaResponse::MODEL_ATTRIBUTE_INTEGER,
            UtopiaResponse::MODEL_ATTRIBUTE_FLOAT,
            UtopiaResponse::MODEL_ATTRIBUTE_EMAIL,
            UtopiaResponse::MODEL_ATTRIBUTE_ENUM,
            UtopiaResponse::MODEL_ATTRIBUTE_URL,
            UtopiaResponse::MODEL_ATTRIBUTE_IP,
            UtopiaResponse::MODEL_ATTRIBUTE_DATETIME,
            UtopiaResponse::MODEL_ATTRIBUTE_RELATIONSHIP,
            UtopiaResponse::MODEL_ATTRIBUTE_STRING,
        ];
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/:key')
            ->desc('Get column')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: 'databases',
                group: $this->getSdkGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-attribute.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
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

        $collection = $dbForProject->getDocument('database_' . $database->getInternalId(), $tableId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException());
        }

        $attribute = $dbForProject->getDocument('attributes', $database->getInternalId() . '_' . $collection->getInternalId() . '_' . $key);
        if ($attribute->isEmpty()) {
            throw new Exception($this->getNotFoundException());
        }

        foreach ($attribute->getAttribute('options', []) as $optKey => $optVal) {
            $attribute->setAttribute($optKey, $optVal);
        }

        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');
        $model = $this->getCorrectModel($type, $format);

        $response->dynamic($attribute, $model);
    }
}
