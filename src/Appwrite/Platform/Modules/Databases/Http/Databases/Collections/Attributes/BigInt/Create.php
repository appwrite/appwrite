<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\BigInt;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class Create extends Action
{
    public static function getName(): string
    {
        return 'createBigIntAttribute';
    }

    protected function getResponseModel(): string|array
    {
        return UtopiaResponse::MODEL_ATTRIBUTE_BIGINT;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/databases/:databaseId/collections/:collectionId/attributes/bigint')
            ->desc('Create bigint attribute')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].attributes.[attributeId].create')
            ->label('audits.event', 'attribute.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/databases/create-bigint-attribute.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_ACCEPTED,
                        model: $this->getResponseModel(),
                    )
                ],
                deprecated: new Deprecated(
                    since: '1.8.0',
                    replaceWith: 'tablesDB.createBigIntColumn',
                ),
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Collection ID.', false, ['dbForProject'])
            ->param('key', '', fn (Database $dbForProject) => new Key(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Attribute Key.', false, ['dbForProject'])
            ->param('required', null, new Boolean(), 'Is attribute required?')
            ->param('min', null, new Nullable(new Integer(false, 64)), 'Minimum value', true)
            ->param('max', null, new Nullable(new Integer(false, 64)), 'Maximum value', true)
            ->param('default', null, new Nullable(new Integer(false, 64)), 'Default value. Cannot be set when attribute is required.', true)
            ->param('array', false, new Boolean(), 'Is attribute an array?', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('publisherForDatabase')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, bool $array, UtopiaResponse $response, Database $dbForProject, DatabasePublisher $publisherForDatabase, Event $queueForEvents, Authorization $authorization): void
    {
        $min ??= \PHP_INT_MIN;
        $max ??= \PHP_INT_MAX;

        if ($min > $max) {
            throw new Exception($this->getInvalidValueException(), 'Minimum value must be lesser than maximum value');
        }

        $validator = new Range($min, $max, Range::TYPE_INTEGER);
        if (!\is_null($default) && !$validator->isValid($default)) {
            throw new Exception($this->getInvalidValueException(), $validator->getDescription());
        }

        $attribute = $this->createAttribute($databaseId, $collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_BIGINT,
            'size' => 8,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_BIGINT_RANGE,
            'formatOptions' => ['min' => $min, 'max' => $max],
        ]), $response, $dbForProject, $publisherForDatabase, $queueForEvents, $authorization);

        $formatOptions = $attribute->getAttribute('formatOptions', []);
        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_ACCEPTED)
            ->dynamic($attribute, $this->getResponseModel());
    }
}
