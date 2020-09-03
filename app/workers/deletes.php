<?php

require_once __DIR__.'/../init.php';

\cli_set_process_title('Deletes V1 Worker');

echo APP_NAME.' deletes worker v1 has started'."\n";

use Appwrite\Database\Database;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Document;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Storage\Device\Local;
use Utopia\CLI\Console;
use Utopia\Config\Config;

class DeletesV1
{
    public $args = [];

    protected $consoleDB = null;

    public function setUp()
    {
    }

    public function perform()
    {
        $projectId = $this->args['projectId'];
        $document = $this->args['document'];
        $document = new Document($document);
        
        switch ($document->getCollection()) {
            case Database::SYSTEM_COLLECTION_PROJECTS:
                $this->deleteProject($document);
                break;
            case Database::SYSTEM_COLLECTION_USERS:
                $this->deleteUser($document);
                break;
            
            default:
                Console::error('No lazy delete operation available for document of type: '.$document->getCollection());
                break;
        }
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }

    protected function deleteProject(Document $document)
    {
        // Delete all DBs
        $this->getConsoleDB()->deleteNamespace($document->getId());
        $uploads = new Local(APP_STORAGE_UPLOADS.'/app-'.$document->getId());
        $cache = new Local(APP_STORAGE_CACHE.'/app-'.$document->getId());

        // Delete all storage directories
        $uploads->delete($uploads->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }

    protected function deleteUser(Document $user)
    {
        global $projectDB;

        $tokens = $user->getAttribute('tokens', []);

        foreach ($tokens as $token) {
            if (!$projectDB->deleteDocument($token->getId())) {
                throw new Exception('Failed to remove token from DB', 500);
            }
        }

        $memberships = $projectDB->getCollection([
            'limit' => 2000, // TODO add members limit
            'offset' => 0,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_MEMBERSHIPS,
                'userId='.$user->getId(),
            ],
        ]);

        foreach ($memberships as $membership) {
            if (!$projectDB->deleteDocument($membership->getId())) {
                throw new Exception('Failed to remove team membership from DB', 500);
            }
        }
    }
}
