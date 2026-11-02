<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Auth\EncryptionKey;
use Appwrite\Database\Factory as DatabaseFactory;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

/**
 * Re-encrypts values stored under an older `_APP_OPENSSL_KEY_V{version}` with
 * the current one, so the old key can be retired. Covers the platform
 * database, every project database in this region, and the columns users
 * created with encryption. Each value is read raw to see its version, so a
 * second run only touches what the first missed.
 */
class Encryption extends Action
{
    public static function getName(): string
    {
        return 'encryption';
    }

    public function __construct()
    {
        $this
            ->desc('Re-encrypt stored data with the current _APP_OPENSSL_KEY_CURRENT version')
            ->param('commit', false, new Boolean(true), 'Write the re-encrypted values. Without it, only count them.', true)
            ->param('after', '', new Text(36, 0), 'Resume after this project ID, the last one a previous run reported done.', true)
            ->param('batch', 100, new Range(1, 1000), 'Documents read per query.', true)
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('databaseFactory')
            ->callback($this->action(...));
    }

    /**
     * @param callable(Document): Database $getProjectDB
     */
    public function action(bool|string $commit, string $after, int|string $batch, Database $dbForPlatform, callable $getProjectDB, DatabaseFactory $databaseFactory): void
    {
        $commit = \strval($commit) === 'true' || $commit === true;
        $batch = (int) $batch;
        $version = EncryptionKey::version();

        if (EncryptionKey::current() === '') {
            Console::error('_APP_OPENSSL_KEY_V' . $version . ' is not configured.');
            Console::exit(1);
            return;
        }

        Console::info(($commit ? 'Re-encrypting' : 'Counting (dry run)') . ' values not encrypted with key version ' . $version);

        $collections = Config::getParam('collections', []);
        $total = 0;

        if ($after === '') {
            foreach (\array_keys($collections['console'] ?? []) as $collection) {
                $total += $this->collection($dbForPlatform, $collection, $version, $commit, $batch);
            }
            Console::log('Project console done');
        }

        $queries = [
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
            Query::orderAsc('$sequence'),
        ];

        if ($after !== '') {
            $last = $dbForPlatform->getDocument('projects', $after);
            if ($last->isEmpty()) {
                Console::error('Project ' . $after . ' not found.');
                Console::exit(1);
                return;
            }
            $queries[] = Query::cursorAfter($last);
        }

        foreach ($dbForPlatform->cursor('projects', $queries, batchSize: $batch) as $project) {
            $dbForProject = $getProjectDB($project);
            $count = 0;

            foreach (\array_keys($collections['projects'] ?? []) as $collection) {
                $count += $this->collection($dbForProject, $collection, $version, $commit, $batch);
            }

            foreach ($dbForProject->cursor('databases', batchSize: $batch) as $database) {
                if (\in_array($database->getAttribute('type', ''), [DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB], true)) {
                    continue; // Schemaless and vector collections have no encrypted columns
                }

                $dbForDatabase = $databaseFactory->tenant($database, $project, preserveDates: true);

                foreach ($dbForProject->cursor('database_' . $database->getSequence(), batchSize: $batch) as $table) {
                    $count += $this->collection($dbForDatabase, 'database_' . $database->getSequence() . '_collection_' . $table->getSequence(), $version, $commit, $batch);
                }
            }

            $total += $count;
            Console::log('Project ' . $project->getId() . ' done, ' . $count . ' documents');
        }

        Console::success(($commit ? 'Re-encrypted ' : 'Found ') . $total . ' documents with values under an older key version');
    }

    /**
     * Re-encrypt one collection's encrypted attributes, returning how many documents held an older version.
     */
    private function collection(Database $database, string $collection, string $version, bool $commit, int $batch): int
    {
        $metadata = $database->findCollection($collection);
        if ($metadata === null) {
            return 0;
        }

        $keys = [];
        foreach ($metadata->attributes() as $attribute) {
            if (\in_array('encrypt', $attribute->filters, true)) {
                $keys[] = $attribute->key;
            }
        }

        if ($keys === []) {
            return 0;
        }

        $count = 0;
        $cursor = null;

        do {
            $queries = [Query::orderAsc('$sequence'), Query::limit($batch)];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            // Raw, so each value still shows the key version it was encrypted with
            $documents = $database->skipFilters(fn () => $database->find($collection, $queries));

            foreach ($documents as $raw) {
                $stale = \array_values(\array_filter($keys, fn (string $key) => $this->isStale($raw->getAttribute($key), $version)));
                if ($stale === []) {
                    continue;
                }

                $count++;

                if (!$commit) {
                    continue;
                }

                $document = $database->getDocument($collection, $raw->getId());
                $update = new Document(\array_combine($stale, \array_map(fn (string $key) => $document->getAttribute($key), $stale)));

                // Writing through the encrypt filter stores the current version
                $database->withPreserveDates(true, fn () => $database->updateDocument($collection, $raw->getId(), $update));
            }

            $cursor = \end($documents) ?: null;
        } while (\count($documents) === $batch);

        if ($count > 0) {
            Console::log('  ' . $collection . ': ' . $count);
        }

        return $count;
    }

    /**
     * Whether a raw value, or any item of an array value, was encrypted with another key version.
     */
    private function isStale(mixed $value, string $version): bool
    {
        if (\is_array($value)) {
            return \array_any($value, fn (mixed $item) => $this->isStale($item, $version));
        }

        if (!\is_string($value)) {
            return false;
        }

        $envelope = \json_decode($value, true);

        return \is_array($envelope) && isset($envelope['version']) && (string) $envelope['version'] !== $version;
    }
}
