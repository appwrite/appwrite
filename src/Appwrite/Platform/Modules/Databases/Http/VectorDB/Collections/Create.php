<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Action as CollectionAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends CollectionAction
{
    public static function getName(): string
    {
        return 'createVectorDBCollection';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_VECTORDB_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vectordb/:databaseId/collections')
            ->desc('Create collection')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].create')
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'collection.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'vectorDB',
                group: 'collections',
                name: 'createCollection',
                description: '/docs/references/vectordb/create-collection.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
            ->param('dimensions', null, new Range(1, 16000), 'Embedding dimensions.')
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $name, int $dimensions, ?array $permissions, bool $documentSecurity, bool $enabled, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionId = $collectionId === 'unique()' ? ID::unique() : $collectionId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions) ?? [];

        try {
            $collection = $dbForProject->createDocument('database_' . $database->getSequence(), new Document([
                '$id' => $collectionId,
                'databaseInternalId' => $database->getSequence(),
                'databaseId' => $databaseId,
                '$permissions' => $permissions,
                'documentSecurity' => $documentSecurity,
                'enabled' => $enabled,
                'name' => $name,
                'dimensions' => $dimensions,
                'search' => \implode(' ', [$collectionId, $name]),
            ]));

        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException());
        } catch (NotFoundException) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }
        /** @var Database $dbForDatabases */
        $dbForDatabases = $getDatabasesDB($database);

        $attributes = [];
        $indexes = [];
        $collections = (Config::getParam('collections', [])['vectordb'] ?? [])['collections'] ?? [];
        foreach ($collections['defaultAttributes'] as $attribute) {
            if ($attribute['$id'] === 'embeddings') {
                $attribute['size'] = $dimensions;
            }
            $attributes[] = new Document($attribute);
        }
        foreach ($collections['defaultIndexes'] as $index) {
            $indexes[] = new Document($index);
        }
        try {
            // passing null in creates only creates the metadata collection
            if (!$dbForDatabases->exists(null, Database::METADATA)) {
                $dbForDatabases->create();
            }
            $dbForDatabases->createCollection(
                id: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                permissions: $permissions,
                documentSecurity: $documentSecurity,
                attributes:$attributes,
                indexes:$indexes
            );
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (IndexException) {
            throw new Exception($this->getInvalidIndexException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException());
        }

        // Create attribute metadata documents in the attributes table
        // This is necessary so that indexes can find the attributes when they're created
        foreach ($collections['defaultAttributes'] as $attributeConfig) {
            $key = \is_string($attributeConfig['$id']) ? $attributeConfig['$id'] : (string)$attributeConfig['$id'];
            $size = $key === 'embeddings' ? $dimensions : ($attributeConfig['size'] ?? 0);

            try {
                $attributeDoc = new Document([
                    '$id' => ID::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $key),
                    'key' => $key,
                    'databaseInternalId' => $database->getSequence(),
                    'databaseId' => $databaseId,
                    'collectionInternalId' => $collection->getSequence(),
                    'collectionId' => $collectionId,
                    'type' => $attributeConfig['type'],
                    'status' => 'available',
                    'size' => $size,
                    'required' => $attributeConfig['required'] ?? false,
                    'signed' => $attributeConfig['signed'] ?? false,
                    'default' => $attributeConfig['default'] ?? null,
                    'array' => $attributeConfig['array'] ?? false,
                    'format' => $attributeConfig['format'] ?? '',
                    'formatOptions' => $attributeConfig['formatOptions'] ?? [],
                    'filters' => $attributeConfig['filters'] ?? [],
                    'options' => $attributeConfig['options'] ?? [],
                ]);

                $dbForProject->createDocument('attributes', $attributeDoc);
            } catch (DuplicateException) {
                // Attribute already exists, skip
            }
        }

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam($this->getEventsParamKey(), $collection->getId());

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_CREATED)
            ->dynamic($collection, $this->getResponseModel());
    }
}
