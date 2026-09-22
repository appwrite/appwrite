<?php

namespace Appwrite\Databases;

use Utopia\Console;
use Utopia\Database\Database;

/**
 * TEMPORARY. Prints the collection cache base keys the caller's Database
 * resolves for the targets metadata row, so the reader that plants the empty
 * marker and the writer that rotates the epoch can be compared in one CI run.
 */
class CacheKeyProbe
{
    public static function log(Database $database, string $site): void
    {
        try {
            [$collectionKey, $documentKey] = $database->getCacheBaseKeys(Database::METADATA, 'targets');

            Console::error(\sprintf(
                'CACHEKEY[%s] host=%s db=%s ns=%s tenant=%s shared=%s collectionKey=%s documentKey=%s adapter=%s',
                $site,
                self::show($database->getAdapter()->getHostname()),
                self::show($database->getAdapter()->getDatabase()),
                self::show($database->getNamespace()),
                self::show($database->getTenant()),
                $database->getSharedTables() ? 'yes' : 'no',
                $collectionKey,
                $documentKey,
                $database->getAdapter()::class,
            ));
        } catch (\Throwable $error) {
            Console::error('CACHEKEY[' . $site . '] probe failed: ' . $error->getMessage());
        }
    }

    private static function show(mixed $value): string
    {
        if ($value === null) {
            return '<null>';
        }

        return $value === '' ? '<empty>' : (string) $value;
    }
}
