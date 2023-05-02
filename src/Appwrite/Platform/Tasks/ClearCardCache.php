<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Platform\Action;
use Utopia\CLI\Console;
use Utopia\Database\Query;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;

class ClearCardCache extends Action
{
    public static function getName(): string
    {
        return 'clear-card-cache';
    }

    public function __construct()
    {
        $this
            ->desc('Deletes card cache for specific user')
            ->param('userId', '', new UID(), 'User UID.', false)
            ->inject('dbForConsole')
            ->callback(fn (string $userId, Database $dbForConsole) => $this->action($userId, $dbForConsole));
    }

    public function action(string $userId, Database $dbForConsole): void
    {
        Authorization::disable();
        Authorization::setDefaultStatus(false);

        Console::title('ClearCardCache V1');
        Console::success(APP_NAME . ' ClearCardCache v1 has started');
        $resources = ['card/' . $userId, 'card-back/' . $userId, 'card-og/' . $userId];

        $caches = Authorization::skip(fn () => $dbForConsole->find('cache', [
            Query::equal('resource', $resources),
            Query::limit(100)
        ]));

        $count = \count($caches);
        Console::info("Going to delete {$count} cache records in 5 seconds...");
        \sleep(5);

        foreach ($caches as $cache) {
            $key = $cache->getId();

            $cacheFolder = new Cache(
                new Filesystem(APP_STORAGE_CACHE . DIRECTORY_SEPARATOR . 'app-console')
            );

            $cacheFolder->purge($key);

            Authorization::skip(fn () => $dbForConsole->deleteDocument('cache', $cache->getId()));
        }

        Console::success(APP_NAME . ' ClearCardCache v1 has finished');
    }
}
