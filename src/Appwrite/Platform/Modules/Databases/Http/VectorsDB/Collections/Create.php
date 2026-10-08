<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections;

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
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Id;
use Utopia\Database\Index;
use Utopia\Database\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Create extends CollectionAction
{
    public static function getName(): string
    {
        return 'createVectorsDBCollection';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_VECTORSDB_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vectorsdb/:databaseId/collections')
            ->desc('Create collection')
            ->groups(['api', 'database'])
            ->label('event', 'databases.[databaseId].collections.[collectionId].create')
            ->label('scope', 'vectorsdb.collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'collection.create')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{response.$id}')
            ->label('usage.resource', 'database/{request.databaseId}/collection/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'vectorsDB',
                group: 'collections',
                name: 'createCollection',
                description: '/docs/references/vectorsdb/create-collection.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_CREATED,
                        model: $this->getResponseModel(),
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Database ID.', false, ['dbForProject'])
            ->param('collectionId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
            ->param('dimension', null, new Range(MIN_VECTOR_DIMENSION, MAX_VECTOR_DIMENSION), 'Embedding dimension.', example: '4')
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permissions strings. By default, no user is granted with any permissions. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, string $name, int $dimension, ?array $permissions, bool $documentSecurity, bool $enabled, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

        if ($database->isEmpty() || $this->isDatabaseTypeMismatch($database)) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collectionId = $collectionId === 'unique()' ? Id::unique() : $collectionId;

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions) ?? [];

        // Resolve the data-plane database BEFORE writing the metadata row. For a
        // dedicated product database this throws while provisioning (no DSN yet);
        // writing the row first would leave an orphaned collection with no backing
        // collection, which lists fine but fails every document operation.
        /** @var Database $dbForDatabases */
        $dbForDatabases = $getDatabasesDB($database);

        try {
            $collection = $dbForProject->createDocument('database_' . $database->getSequence(), new Document([
                '$id' => $collectionId,
                'databaseInternalId' => $database->getSequence(),
                'databaseId' => $databaseId,
                '$permissions' => $permissions,
                'documentSecurity' => $documentSecurity,
                'enabled' => $enabled,
                'name' => $name,
                'dimension' => $dimension,
                'search' => \implode(' ', [$collectionId, $name]),
            ]));

        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException());
        } catch (NotFoundException) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collections = (Config::getParam('collections', [])['vectorsdb'] ?? [])['collections'] ?? [];
        $attributes = \array_map(
            static fn (Attribute $attribute): Attribute => $attribute->key === 'embeddings'
                ? Attribute::vector($attribute->key, dimensions: $dimension, required: $attribute->required)
                : $attribute,
            $collections['defaultAttributes'],
        );
        $indexes = $collections['defaultIndexes'];
        try {
            // Bootstrap the database metadata without a separate existence
            // check to avoid races when multiple first collections are created
            // concurrently for the same VectorsDB database.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $dbForDatabases->create();
                    break;
                } catch (DuplicateException) {
                    break;
                } catch (\Throwable $e) {
                    if ($dbForDatabases->collectionExists(Database::METADATA, null)) {
                        break;
                    }

                    if ($attempt === 4) {
                        throw $e;
                    }

                    \usleep(100_000);
                }
            }
            $dbForDatabases->createCollection(Collection::create(
                id: 'database_' . $database->getSequence() . '_collection_' . $collection->getSequence(),
                attributes: $attributes,
                indexes: $indexes,
                permissions: $permissions,
                documentSecurity: $documentSecurity,
            ));
            $attributeDocuments = \array_map(function (Attribute $attribute) use ($database, $collection, $databaseId, $collectionId, $dimension) {
                return new Document([
                    '$id' => Id::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $attribute->key),
                    'key' => $attribute->key,
                    'databaseInternalId' => $database->getSequence(),
                    'databaseId' => $databaseId,
                    'collectionInternalId' => $collection->getSequence(),
                    'collectionId' => $collectionId,
                    'type' => $attribute->type->value,
                    'status' => 'available',
                    'size' => $dimension,
                    'required' => $attribute->required,
                    'signed' => $attribute->signed,
                    'default' => $attribute->default,
                    'array' => $attribute->array,
                    'format' => $attribute->format?->name ?? '',
                    'formatOptions' => $attribute->format?->options ?? [],
                    'filters' => $attribute->filters,
                    'options' => $attribute->toDocument()->getAttribute('options', []),
                ]);
            }, $collections['defaultAttributes']);
            $dbForProject->createDocuments('attributes', $attributeDocuments);

            $indexDocuments = \array_map(function (Index $index) use ($database, $collection, $databaseId, $collectionId) {
                $definition = $index->toDocument();

                return new Document([
                    '$id' => Id::custom($database->getSequence() . '_' . $collection->getSequence() . '_' . $index->key),
                    'key' => $index->key,
                    'status' => 'available',
                    'databaseInternalId' => $database->getSequence(),
                    'databaseId' => $databaseId,
                    'collectionInternalId' => $collection->getSequence(),
                    'collectionId' => $collectionId,
                    'type' => $index->type->value,
                    'attributes' => $index->attributes,
                    'lengths' => $definition->getAttribute('lengths', []),
                    'orders' => $definition->getAttribute('orders', []),
                ]);
            }, $collections['defaultIndexes']);

            if (!empty($indexDocuments)) {
                $dbForProject->createDocuments('indexes', $indexDocuments);
            }
        } catch (DuplicateException) {
            throw new Exception($this->getDuplicateException());
        } catch (IndexException) {
            throw new Exception($this->getInvalidIndexException());
        } catch (LimitException) {
            throw new Exception($this->getLimitException());
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
