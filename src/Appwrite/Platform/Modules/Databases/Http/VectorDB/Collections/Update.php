<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Action as CollectionAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

class Update extends CollectionAction
{
    public static function getName(): string
    {
        return 'updateDocumentsDBCollection';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_VECTORDB_COLLECTION;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/vectordb/:databaseId/collections/:collectionId')
            ->desc('Update collection')
            ->groups(['api', 'database', 'schema'])
            ->label('scope', 'collections.write')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('event', 'databases.[databaseId].collections.[collectionId].update')
            ->label('audits.event', 'collection.update')
            ->label('audits.resource', 'database/{request.databaseId}/collection/{request.collectionId}')
            ->label('sdk', new Method(
                namespace: 'documentsDB',
                group: 'collections',
                name: 'updateCollection',
                description: '/docs/references/vectordb/update-collection.md',
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_VECTORDB_COLLECTION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('databaseId', '', new UID(), 'Database ID.')
            ->param('collectionId', '', new UID(), 'Collection ID.')
            ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
            ->param('description', null, new Text(1024), 'Collection description. Max length: 1024 chars.', true)
            ->param('dimensions', null, new Integer(), 'Embedding dimensions.', true)
            ->param('embeddingModel', null, new Text(256), 'Embedding model identifier.', true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, ?string $name, ?string $description, ?int $dimensions, ?string $embeddingModel, ?array $permissions, bool $documentSecurity, bool $enabled, UtopiaResponse $response, \Utopia\Database\Database $dbForProject, callable $getDatabasesDB, \Appwrite\Event\Event $queueForEvents): void
    {
        $database = Authorization::skip(fn () => $dbForProject->getDocument('databases', $databaseId));
        if ($database->isEmpty()) {
            throw new Exception(Exception::DATABASE_NOT_FOUND);
        }

        $collection = $dbForProject->getDocument('database_' . $database->getSequence(), $collectionId);
        if ($collection->isEmpty()) {
            throw new Exception($this->getNotFoundException());
        }

        $permissions ??= $collection->getPermissions();

        // Map aggregate permissions into the multiple permissions they represent.
        $permissions = Permission::aggregate($permissions);

        $enabled ??= $collection->getAttribute('enabled', true);

        $updated = $dbForProject->updateDocument(
            'database_' . $database->getSequence(),
            $collectionId,
            $collection
                ->setAttribute('name', $name ?? $collection->getAttribute('name'))
                ->setAttribute('description', $description ?? $collection->getAttribute('description'))
                ->setAttribute('dimensions', $dimensions ?? $collection->getAttribute('dimensions'))
                ->setAttribute('embeddingModel', $embeddingModel ?? $collection->getAttribute('embeddingModel'))
                ->setAttribute('$permissions', $permissions)
                ->setAttribute('documentSecurity', $documentSecurity)
                ->setAttribute('enabled', $enabled)
                ->setAttribute('search', \implode(' ', [$collectionId, $name ?? $collection->getAttribute('name')]))
        );

        $dbForDatabases = $getDatabasesDB($database);
        $dbForDatabases->updateCollection('database_' . $database->getSequence() . '_collection_' . $updated->getSequence(), $permissions, $documentSecurity);

        $queueForEvents
            ->setContext('database', $database)
            ->setParam('databaseId', $databaseId)
            ->setParam($this->getEventsParamKey(), $updated->getId());

        $response->dynamic($updated, $this->getResponseModel());
    }
}
