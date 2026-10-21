<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console\Console;
use Utopia\Database\Attribute;
use Utopia\Database\Attribute\Update as AttributeUpdate;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Migration\Resource;
use Utopia\Query\Schema\ColumnType;

class V25 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        Console::info('Migrating collections');
        $this->migrateCollections();

        Console::info('Migrating documents');
        $this->forEachDocument($this->migrateDocument(...));
    }

    /**
     * Migrate Collections.
     *
     * @return void
     * @throws Exception|Throwable
     */
    private function migrateCollections(): void
    {
        $projectInternalId = $this->project->getSequence();

        if (empty($projectInternalId)) {
            throw new Exception('Project ID is null');
        }

        $collectionType = match ($projectInternalId) {
            'console' => 'console',
            default => 'projects',
        };

        $collections = $this->collections[$collectionType];

        foreach ($collections as $collection) {
            $id = $collection['$id'];

            if (empty($id)) {
                continue;
            }

            Console::log("Migrating collection \"{$id}\"");

            $this->dbForProject->purgeCachedCollection($id);
            $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);

            switch ($id) {
                case 'projects':
                    if ($collectionType === 'console') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'onboarding');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"onboarding\" in collection {$id}: {$th->getMessage()}");
                        }

                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, '_key_accessedAt');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"_key_accessedAt\" from {$id}: {$th->getMessage()}");
                        }

                        $attributes = \array_map(
                            fn (Attribute $attribute): string => $attribute->key,
                            $this->dbForProject->findCollection($id)?->attributes() ?? []
                        );
                        if (\in_array('devKeys', $attributes, true)) {
                            $this->dbForProject->deleteAttribute($id, 'devKeys');
                        }

                        if ($this->dbForProject->findCollection('devKeys') !== null) {
                            $this->dbForProject->deleteCollection('devKeys');
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'schedules':
                    if ($collectionType === 'console') {
                        foreach (['projectInternalId', 'interval'] as $attribute) {
                            try {
                                $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                            }
                        }

                        $this->dbForProject->purgeCachedCollection($id);

                        $indexes = [
                            '_key_region_resourceType_projectInternalId_resourceId',
                            '_key_project_internal_id_region',
                        ];
                        foreach ($indexes as $index) {
                            try {
                                $this->createIndexFromCollection($this->dbForProject, $id, $index);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create index \"{$index}\" from {$id}: {$th->getMessage()}");
                            }
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'notifications':
                    if ($collectionType === 'console') {
                        $attributes = ['teamId', 'teamInternalId'];
                        foreach ($attributes as $attribute) {
                            try {
                                $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                            }
                        }

                        $this->dbForProject->purgeCachedCollection($id);

                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, '_key_team');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"_key_team\" from {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'platforms':
                    if ($collectionType === 'console') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'fingerprints');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"fingerprints\" in collection {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'installations':
                    if ($collectionType === 'console') {
                        foreach (['personalAccessToken', 'personalRefreshToken'] as $attribute) {
                            try {
                                $this->dbForProject->updateAttribute($id, $attribute, new AttributeUpdate(type: ColumnType::Text, size: Database::MAX_TEXT_BYTES));
                            } catch (Throwable $th) {
                                Console::warning("Failed to convert attribute \"{$attribute}\" to text in collection {$id}: {$th->getMessage()}");
                            }
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'webhooks':
                    // The encrypted password is about 1.4x its plaintext plus a 121-char wrapper, so the 255-char column
                    // only fit passwords of up to about 99 chars, well below the 2048 the API accepts.
                    if ($collectionType === 'console') {
                        try {
                            $this->dbForProject->updateAttribute($id, 'httpPass', new AttributeUpdate(type: ColumnType::Text, size: Database::MAX_TEXT_BYTES));
                        } catch (Throwable $th) {
                            Console::warning("Failed to convert attribute \"httpPass\" to text in collection {$id}: {$th->getMessage()}");
                        }
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'description');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"description\" in collection {$id}: {$th->getMessage()}");
                        }
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'challenges':
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_expire');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create index \"_key_expire\" from {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'passkey');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"passkey\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'authenticators':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'identifier');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"identifier\" in collection {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_identifier');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create index \"_key_identifier\" from {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'name');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"name\" in collection {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'accessedAt');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"accessedAt\" in collection {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_accessedAt');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create index \"_key_accessedAt\" from {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'databases':
                    if ($collectionType === 'projects') {
                        foreach (['status', 'description'] as $attribute) {
                            try {
                                $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                            }
                        }
                        $this->dbForProject->purgeCachedCollection($id);

                        foreach ($this->documentsIterator($id) as $database) {
                            $databaseTable = 'database_' . $database->getSequence();
                            try {
                                $this->createAttributeFromCollection($this->dbForProject, $databaseTable, 'description', 'collections');
                            } catch (Throwable $th) {
                                Console::warning("Failed to create attribute \"description\" in collection {$databaseTable}: {$th->getMessage()}");
                            }
                            $this->dbForProject->purgeCachedCollection($databaseTable);
                        }

                        // Backfill existing databases so the stored value matches the intended default.
                        // Materialize the matched documents before updating any of them: updating
                        // "status" removes rows from the isNull() set, and the offset-based iterator
                        // would otherwise skip un-processed rows as the filtered set shrinks mid-scan.
                        $databases = \iterator_to_array($this->documentsIterator($id, [Query::isNull('status')]));
                        foreach ($databases as $database) {
                            try {
                                $this->dbForProject->updateDocument($id, $database->getId(), new Document([
                                    'status' => 'ready',
                                ]));
                            } catch (Throwable $th) {
                                Console::warning("Failed to backfill \"status\" for database {$database->getId()}: {$th->getMessage()}");
                            }
                        }
                    }
                    break;

                case 'functions':
                case 'sites':
                    if ($collectionType === 'projects') {
                        $attributes = ['providerBranches', 'providerPaths', 'description'];
                        if ($id === 'sites') {
                            $attributes[] = 'scopes';
                        }
                        if ($id === 'functions') {
                            $attributes[] = 'interval';
                        }
                        try {
                            $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                        } catch (Throwable $th) {
                            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                        }

                        $this->dbForProject->purgeCachedCollection($id);
                        $this->dbForProject->purgeCachedDocument(Database::METADATA, $id);
                    }
                    break;

                case 'migrations':
                    if ($collectionType === 'projects') {
                        $attributes = [
                            'resourceInternalId',
                            'parentResourceId',
                            'parentResourceInternalId',
                            'parentResourceType',
                            'destinationResourceId',
                            'destinationResourceInternalId',
                            'destinationResourceType',
                        ];
                        try {
                            $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                        } catch (Throwable $th) {
                            Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                        }

                        $indexes = [
                            '_key_resourceType',
                            '_key_resourceInternalId',
                            '_key_parentResourceId',
                            '_key_parentResourceType',
                            '_key_parentResourceInternalId',
                            '_key_destinationResourceId',
                            '_key_destinationResourceInternalId',
                            '_key_destinationResourceType',
                        ];
                        foreach ($indexes as $index) {
                            try {
                                $this->createIndexFromCollection($this->dbForProject, $id, $index);
                            } catch (Throwable $th) {
                                Console::warning("Failed to create index \"{$index}\" from {$id}: {$th->getMessage()}");
                            }
                        }

                        $this->dbForProject->purgeCachedCollection($id);
                    }
                    break;

                case 'deployments':
                    if ($collectionType === 'projects') {
                        try {
                            $this->createIndexFromCollection($this->dbForProject, $id, '_key_commitHash_branch');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create index \"_key_commitHash_branch\" from {$id}: {$th->getMessage()}");
                        }

                        $this->dbForProject->purgeCachedCollection($id);
                    }
                    break;

                case 'users':
                    // Added in V23. An install whose V23 users step failed, or one that
                    // upgraded straight from 1.8.x, arrives here without them and every
                    // user write fails with Unknown attribute: "emailCanonical".
                    $attributes = [
                        'emailCanonical',
                        'emailIsFree',
                        'emailIsDisposable',
                        'emailIsCorporate',
                        'emailIsCanonical',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'passwordPwned');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"passwordPwned\" in collection {$id}: {$th->getMessage()}");
                    }

                    try {
                        $this->createIndexFromCollection($this->dbForProject, $id, '_key_passwordPwned');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create index \"_key_passwordPwned\" from {$id}: {$th->getMessage()}");
                    }

                    // Added in 2.4.0 for custom user photos
                    foreach (['photoId', 'photoSize'] as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'identities':
                    foreach (['photo', 'providerIdToken'] as $attribute) {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, $attribute);
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"{$attribute}\" in collection {$id}: {$th->getMessage()}");
                        }
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'attributes':
                case 'indexes':
                case 'variables':
                    if ($collectionType === 'projects') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'description');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"description\" in collection {$id}: {$th->getMessage()}");
                        }
                        $this->dbForProject->purgeCachedCollection($id);
                    }
                    break;

                case 'keys':
                    if ($collectionType === 'console') {
                        try {
                            $this->createAttributeFromCollection($this->dbForProject, $id, 'description');
                        } catch (Throwable $th) {
                            Console::warning("Failed to create attribute \"description\" in collection {$id}: {$th->getMessage()}");
                        }
                        $this->dbForProject->purgeCachedCollection($id);
                    }
                    break;

                case 'presenceLogs':
                    // Added in 1.9.5 and created only by V24, which an install that ran the 1.9.6
                    // migration or upgraded straight from 1.8.x never ran. createCollection() is a
                    // no-op when it already exists and creates it with connections when it doesn't;
                    // the attribute-add then covers installs that have it from V24 without the column.
                    // A Duplicate means createCollection already made the row with connections; any
                    // other failure propagates so the version is not recorded with a missing attribute.
                    $this->createCollection($id);
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'connections');
                    } catch (Duplicate) {
                        // createCollection already made the row with connections; nothing to add.
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'pushLedger':
                    // Added in 2.3.0 for every project; an install upgraded from 2.2.0
                    // has no table yet, and migrating its documents fails without one.
                    $this->createCollection($id);
                    break;

                case 'buckets':
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'description');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"description\" in collection {$id}: {$th->getMessage()}");
                    }
                    try {
                        $this->createAttributeFromCollection($this->dbForProject, $id, 'stripMetadata');
                    } catch (Throwable $th) {
                        Console::warning("Failed to create attribute \"stripMetadata\" in collection {$id}: {$th->getMessage()}");
                    }
                    $this->dbForProject->purgeCachedCollection($id);
                    break;

                case 'topics':
                    // Added in 2.3.0 for the push broker, which increments sequence on publish.
                    $attributes = [
                        'sequence',
                        'qos',
                        'expiry',
                        'description',
                    ];
                    try {
                        $this->createAttributesFromCollection($this->dbForProject, $id, $attributes);
                    } catch (Throwable $th) {
                        Console::warning('Failed to create attributes "' . \implode(', ', $attributes) . "\" in collection {$id}: {$th->getMessage()}");
                    }

                    $this->dbForProject->purgeCachedCollection($id);
                    break;
            }
        }
    }

    protected function migrateDocument(Document $document): Document
    {
        if (\in_array($document->getCollection(), ['keys', 'functions', 'sites'], true)) {
            $retired = ['devKeys.read', 'devKeys.write', 'assistant.read'];
            $scopes = $document->getAttribute('scopes', []);
            if (\is_array($scopes) && \array_intersect($scopes, $retired) !== []) {
                $document->setAttribute('scopes', \array_values(\array_diff($scopes, $retired)));
            }

            return $document;
        }

        if ($document->getCollection() !== 'migrations') {
            return $document;
        }

        $resourceId = (string) $document->getAttribute('resourceId', '');
        $parentResourceId = (string) $document->getAttribute('parentResourceId', '');
        $parentResourceType = '';
        $split = false;

        if ($parentResourceId === '') {
            if (!\str_contains($resourceId, ':')) {
                return $document;
            }

            [$parentResourceId, $resourceId] = \explode(':', $resourceId, 2);
            if ($parentResourceId === '' || $resourceId === '') {
                return $document;
            }

            $parentResourceType = (string) $document->getAttribute('resourceType', '');
            $split = true;
        }

        if ($resourceId === '') {
            return $document;
        }

        $internalIds = $this->resolveInternalIds($parentResourceId, $resourceId, $document);
        if (
            $split
            && (!isset($internalIds['parentResourceInternalId']) || !isset($internalIds['resourceInternalId']))
        ) {
            return $document;
        }

        if ($split) {
            $document
                ->setAttribute('resourceId', $resourceId)
                ->setAttribute('resourceType', Resource::TYPE_COLLECTION)
                ->setAttribute('parentResourceId', $parentResourceId);

            if ($parentResourceType !== '') {
                $document->setAttribute('parentResourceType', $parentResourceType);
            }
        }

        if (
            (string) $document->getAttribute('parentResourceInternalId', '') === ''
            && isset($internalIds['parentResourceInternalId'])
        ) {
            $document->setAttribute('parentResourceInternalId', $internalIds['parentResourceInternalId']);
        }
        if (
            (string) $document->getAttribute('resourceInternalId', '') === ''
            && isset($internalIds['resourceInternalId'])
        ) {
            $document->setAttribute('resourceInternalId', $internalIds['resourceInternalId']);
        }

        return $document;
    }

    /**
     * @return array{parentResourceInternalId?: string, resourceInternalId?: string}
     */
    protected function resolveInternalIds(string $parentResourceId, string $resourceId, Document $migration): array
    {
        try {
            $database = $this->dbForProject->getDocument('databases', $parentResourceId);
            if (
                $database->isEmpty()
                || $database->getSequence() === ''
                || !$this->predatesMigration($database, $migration)
            ) {
                return [];
            }
        } catch (Throwable $th) {
            Console::warning("Failed to resolve parent internal ID for migration {$migration->getId()}: {$th->getMessage()}");
            return [];
        }

        $internalIds = [
            'parentResourceInternalId' => (string) $database->getSequence(),
        ];

        try {
            $resource = $this->dbForProject->getDocument('database_' . $database->getSequence(), $resourceId);
            if (
                !$resource->isEmpty()
                && $resource->getSequence() !== ''
                && $this->predatesMigration($resource, $migration)
            ) {
                $internalIds['resourceInternalId'] = (string) $resource->getSequence();
            }
        } catch (Throwable $th) {
            Console::warning("Failed to resolve resource internal ID for migration {$migration->getId()}: {$th->getMessage()}");
        }

        return $internalIds;
    }

    /**
     * A resource created after the migration is a reused public ID, not the
     * generation the historical migration operated on.
     */
    protected function predatesMigration(Document $resource, Document $migration): bool
    {
        $resourceCreatedAt = \strtotime($resource->getCreatedAt() ?? '');
        $migrationCreatedAt = \strtotime($migration->getCreatedAt() ?? '');

        if ($resourceCreatedAt === false || $migrationCreatedAt === false) {
            return false;
        }

        return $resourceCreatedAt <= $migrationCreatedAt;
    }
}
