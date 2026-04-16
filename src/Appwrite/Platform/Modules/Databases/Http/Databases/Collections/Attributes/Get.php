<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
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
        return 'getAttribute';
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
            ->desc('Get attribute')
            ->groups(['api', 'database'])
            ->label('scope', 'collections.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/get-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: $this->getResponseModel()
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.getColumn',
                ),
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('key', '', new Key(), 'Attribute Key.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, UtopiaResponse $response, Database $dbForProject, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND, params: [$databaseId]);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getParentNotFoundException(), params: [$collectionId]);
        }

        $attribute = $dbForProject->getDocument('attributes', $database->getSequence() . '_' . $collection->getSequence() . '_' . $key);
        if ($attribute->isEmpty()) {
            throw new Exception($this->getNotFoundException(), params: [$key]);
        }

        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');
        $options = $attribute->getAttribute('options', []);
        $filters = $attribute->getAttribute('filters', []);
        foreach ($options as $key => $option) {
            $attribute->setAttribute($key, $option);
        }

        $model = $this->getModel($type, $format);

        $attribute->setAttribute('encrypt', in_array('encrypt', $filters));

        $response->dynamic($attribute, $model);
    }
}
