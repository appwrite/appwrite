<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;

class V13 extends Migration
{
    public array $events = [
        'account.create',
        'account.update.email',
        'account.update.name',
        'account.update.password',
        'account.update.prefs',
        'account.recovery.create',
        'account.recovery.update',
        'account.verification.create',
        'account.verification.update',
        'account.delete',
        'account.sessions.create',
        'account.sessions.delete',
        'database.collections.create',
        'database.collections.update',
        'database.collections.delete',
        'database.attributes.create',
        'database.attributes.delete',
        'database.indexes.create',
        'database.indexes.delete',
        'database.documents.create',
        'database.documents.update',
        'database.documents.delete',
        'functions.create',
        'functions.update',
        'functions.delete',
        'functions.deployments.create',
        'functions.deployments.update',
        'functions.deployments.delete',
        'functions.executions.create',
        'functions.executions.update',
        'storage.files.create',
        'storage.files.update',
        'storage.files.delete',
        'storage.buckets.create',
        'storage.buckets.update',
        'storage.buckets.delete',
        'users.create',
        'users.update.prefs',
        'users.update.email',
        'users.update.name',
        'users.update.password',
        'users.update.status',
        'users.sessions.delete',
        'users.delete',
        'teams.create',
        'teams.update',
        'teams.delete',
        'teams.memberships.create',
        'teams.memberships.update',
        'teams.memberships.update.status',
        'teams.memberships.delete'
    ];

    public function execute(): void
    {
        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');
        Console::info('Migrating Collections');
        $this->migrateCollections();
        Console::info('Migrating Documents');
        $this->forEachDocument([$this, 'fixDocument']);
    }

    /**
     * Migrate all Collections.
     *
     * @return void
     */
    protected function migrateCollections(): void
    {
        foreach ($this->collections as $collection) {
            $id = $collection['$id'];

            Console::log("- {$id}");
            switch ($id) {
                case 'projects':
                    try {
                        /**
                         * Rename providers to authProviders.
                         */
                        $this->projectDB->renameAttribute($id, 'providers', 'authProviders');
                    } catch (\Throwable $th) {
                        Console::warning("'providers' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'users':
                    try {
                        /**
                         * Recreate sessions for new subquery.
                         */
                        $this->projectDB->deleteAttribute($id, 'sessions');
                        $this->projectDB->createAttribute(
                            collection: $id,
                            id: 'sessions',
                            required: false,
                            type: Database::VAR_STRING,
                            format: '',
                            size: 16384,
                            filters: ['subQuerySessions']
                        );
                    } catch (\Throwable $th) {
                        Console::warning("'sessions' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Recreate tokens for new subquery.
                         */
                        $this->projectDB->deleteAttribute($id, 'tokens');
                        $this->projectDB->createAttribute(
                            collection: $id,
                            id: 'tokens',
                            required: false,
                            type: Database::VAR_STRING,
                            format: '',
                            size: 16384,
                            filters: ['subQueryTokens']
                        );
                    } catch (\Throwable $th) {
                        Console::warning("'tokens' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Recreate memberships for new subquery.
                         */
                        $this->projectDB->deleteAttribute($id, 'memberships');
                        $this->projectDB->createAttribute(
                            collection: $id,
                            id: 'memberships',
                            required: false,
                            type: Database::VAR_STRING,
                            format: '',
                            size: 16384,
                            filters: ['subQueryMemberships']
                        );
                    } catch (\Throwable $th) {
                        Console::warning("'memberships' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'sessions':
                    try {
                        /**
                         * Add new index for users.
                         */
                        $this->projectDB->createIndex(collection: $id, id: '_key_user', type: Database::INDEX_KEY, attributes: ['userId'], orders: [Database::ORDER_ASC]);
                    } catch (\Throwable $th) {
                        Console::warning("'_key_user' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'builds':
                    try {
                        /**
                         * Increase stdout size.
                         */
                        $this->projectDB->updateAttribute($id, 'stdout', size: 1_000_000);
                    } catch (\Throwable $th) {
                        Console::warning("'stdout' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Increase stderr size.
                         */
                        $this->projectDB->updateAttribute($id, 'stderr', size: 1_000_000);
                    } catch (\Throwable $th) {
                        Console::warning("'stderr' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'executions':
                    try {
                        /**
                         * Rename stdout to response.
                         * Increase response size.
                         */
                        $this->projectDB->renameAttribute($id, 'stdout', 'response');
                        $this->projectDB->updateAttribute($id, 'response', size: 1_000_000);
                    } catch (\Throwable $th) {
                        Console::warning("'stdout' from {$id}: {$th->getMessage()}");
                    }
                    try {
                        /**
                         * Increase stderr size.
                         */
                        $this->projectDB->updateAttribute($id, 'stderr', size: 1_000_000);
                    } catch (\Throwable $th) {
                        Console::warning("'stderr' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'stats':
                    try {
                        /**
                         * Increase value size ot BIGINT.
                         */
                        $this->projectDB->updateAttribute($id, 'value', size: 8);
                    } catch (\Throwable $th) {
                        Console::warning("'size' from {$id}: {$th->getMessage()}");
                    }
                    break;
                case 'tokens':
                    try {
                        /**
                         * Create new Tokens collection.
                         */
                        $this->createCollection('tokens');
                    } catch (\Throwable $th) {
                        Console::warning("'tokens': {$th->getMessage()}");
                    }
                    break;
            }
            usleep(100000);
        }
    }

    /**
     * Fix run on each document
     *
     * @param \Utopia\Database\Document $document
     * @return \Utopia\Database\Document
     */
    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '0.14.0');

                break;

            case 'functions':
                /**
                 * Migrate events.
                 */
                if (!empty($document->getAttribute('events'))) {
                    $document->setAttribute('events', $this->migrateEvents($document->getAttribute('events')));
                }

                break;

            case 'webhooks':
                /**
                 * Migrate events.
                 */
                if (!empty($document->getAttribute('events'))) {
                    $document->setAttribute('events', $this->migrateEvents($document->getAttribute('events')));
                }

                break;

            case 'users':
                /**
                 * Remove deleted users.
                 */
                if ($document->getAttribute('deleted', false) === true) {
                    $this->projectDB->deleteDocument('users', $document->getId());
                }
                break;
        }

        return $document;
    }

    public function migrateEvents(array $events): array
    {
        return array_filter(array_unique(array_map(function ($event) {
            if (!in_array($event, $this->events)) {
                return $event;
            }
            $parts = \explode('.', $event);
            $first = array_shift($parts);
            switch ($first) {
                case 'account':
                case 'users':
                    $first = 'users';

                    switch ($parts[0]) {
                        case 'recovery':
                        case 'sessions':
                        case 'verification':
                            $second = array_shift($parts);
                            return 'users.*.' . $second . '.*.' . implode('.', $parts);

                        default:
                            return 'users.*.' . implode('.', $parts);
                    }
                case 'functions':
                    switch ($parts[0]) {
                        case 'deployments':
                        case 'executions':
                            $second = array_shift($parts);
                            return 'functions.*.' . $second . '.*.' . implode('.', $parts);

                        default:
                            return 'functions.*.' . implode('.', $parts);
                    }
                case 'teams':
                    switch ($parts[0]) {
                        case 'memberships':
                            $second = array_shift($parts);
                            return 'teams.*.' . $second . '.*.' . implode('.', $parts);

                        default:
                            return 'teams.*.' . implode('.', $parts);
                    }
                case 'storage':
                    $second = array_shift($parts);
                    switch ($second) {
                        case 'buckets':
                            return 'buckets.*.' . implode('.', $parts);
                        case 'files':
                            return 'buckets.*.' . $second . '.*.' . implode('.', $parts);
                    } // intentional fallthrough
                case 'database':
                    $second = array_shift($parts);
                    switch ($second) {
                        case 'collections':
                            return 'collections.*.' . implode('.', $parts);
                        case 'documents':
                        case 'indexes':
                        case 'attributes':
                            return 'collections.*.' . $second . '.*.' . implode('.', $parts);
                    }
            }
            return '';
        }, $events)));
    }
}
