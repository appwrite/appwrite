<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Collections;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Action as CollectionAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends CollectionAction
{
    public static function getName(): string
    {
        return 'updateVectorDBCollection';
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
                namespace: 'vectorDB',
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
            ->param('dimension', null, new Range(MIN_VECTOR_DIMENSION, MAX_VECTOR_DIMENSION), 'Embedding dimensions.', true)
            ->param('permissions', null, new Permissions(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of permission strings. By default, the current permissions are inherited. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('documentSecurity', false, new Boolean(true), 'Enables configuring permissions for individual documents. A user needs one of document or collection level permissions to access a document. [Learn more about permissions](https://appwrite.io/docs/permissions).', true)
            ->param('enabled', true, new Boolean(), 'Is collection enabled? When set to \'disabled\', users cannot access the collection but Server SDKs with and API key can still read and write to the collection. No data is lost when this is toggled.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->inject('queueForEvents')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $databaseId, string $collectionId, ?string $name, ?int $dimensions, ?array $permissions, bool $documentSecurity, bool $enabled, UtopiaResponse $response, Database $dbForProject, callable $getDatabasesDB, Event $queueForEvents, Authorization $authorization): void
    {
        $database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
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
                ->setAttribute('dimension', $dimensions ?? $collection->getAttribute('dimension'))
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
