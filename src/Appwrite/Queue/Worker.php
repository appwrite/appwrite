<?php

namespace Appwrite\Queue;

use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;

class Worker
{
    public const DATABASE_PROJECT = 'project';
    public const DATABASE_CONSOLE = 'console';

    /**
     * Get internal project database
     * @param string $projectId
     * @return Database
     */
    public function getProjectDB(string $projectId): Database
    {
        $consoleDB = $this->getConsoleDB();

        if ($projectId === 'console') {
            return $consoleDB;
        }

        /** @var Document $project */
        $project = Authorization::skip(fn() => $consoleDB->getDocument('projects', $projectId));

        return $this->getDB(self::DATABASE_PROJECT, $projectId, $project->getInternalId());
    }

    /**
     * Get console database
     * @return Database
     */
    private function getConsoleDB(): Database
    {
        return $this->getDB(self::DATABASE_CONSOLE);
    }

    /**
     * Get console database
     * @param string $type One of (internal, external, console)
     * @param string $projectId of internal or external DB
     * @return Database
     */
    private function getDB(string $type, string $projectId = '', string $projectInternalId = ''): Database
    {
        global $register;

        $namespace = '';
        $sleep = DATABASE_RECONNECT_SLEEP; // overwritten when necessary

        switch ($type) {
            case self::DATABASE_PROJECT:
                if (!$projectId) {
                    throw new \Exception('ProjectID not provided - cannot get database');
                }
                $namespace = "_{$projectInternalId}";
                break;
            case self::DATABASE_CONSOLE:
                $namespace = "_console";
                $sleep = 5; // ConsoleDB needs extra sleep time to ensure tables are created
                break;
            default:
                throw new \Exception('Unknown database type: ' . $type);
                break;
        }

        $attempts = 0;

        do {
            try {
                $attempts++;
                $cache = new Cache(new RedisCache($register->get('cache')));
                $database = new Database(new MariaDB($register->get('db')), $cache);
                $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
                $database->setNamespace($namespace); // Main DB

                if (!empty($projectId) && !$database->getDocument('projects', $projectId)->isEmpty()) {
                    throw new \Exception("Project does not exist: {$projectId}");
                }

                if ($type === self::DATABASE_CONSOLE && !$database->exists($database->getDefaultDatabase(), Database::METADATA)) {
                    throw new \Exception('Console project not ready');
                }

                break; // leave loop if successful
            } catch (\Exception $e) {
                Console::warning("Database not ready. Retrying connection ({$attempts})...");
                if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                    throw new \Exception('Failed to connect to database: ' . $e->getMessage());
                }
                sleep($sleep);
            }
        } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

        return $database;
    }
}
