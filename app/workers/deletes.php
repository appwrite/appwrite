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
            case Database::COLLECTION_PROJECTS:
                $this->deleteProject($document);
                break;
            case Database::COLLECTION_FUNCTIONS:
                $this->deleteFunction($document, $projectId);
                break;
            case Database::COLLECTION_USERS:
                $this->deleteUser($document, $projectId);
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

    protected function deleteUser(Document $document, $projectId)
    {
        $tokens = $document->getAttribute('tokens', []);

        foreach ($tokens as $token) {
            if (!$this->getProjectDB($projectId)->deleteDocument(Database::COLLECTION_TOKENS, $token->getId())) {
                throw new Exception('Failed to remove token from DB', 500);
            }
        }

        // Delete Memberships
        $this->deleteByGroup([
            '$collection='.Database::COLLECTION_MEMBERSHIPS,
            'userId='.$document->getId(),
        ], $this->getProjectDB($projectId));
    }

    protected function deleteFunction(Document $document, $projectId)
    {
        $projectDB = $this->getProjectDB($projectId);
        $device = new Local(APP_STORAGE_FUNCTIONS.'/app-'.$projectId);

        // Delete Tags
        $this->deleteByGroup([
            '$collection='.Database::COLLECTION_TAGS,
            'functionId='.$document->getId(),
        ], $projectDB, function(Document $document) use ($device) {

            if ($device->delete($document->getAttribute('path', ''))) {
                Console::success('Delete code tag: '.$document->getAttribute('path', ''));
            }
            else {
                Console::error('Dailed to delete code tag: '.$document->getAttribute('path', ''));
            }
        });

        // Delete Executions
        $this->deleteByGroup([
            '$collection='.Database::COLLECTION_EXECUTIONS,
            'functionId='.$document->getId(),
        ], $projectDB);
    }

    protected function deleteById(Document $document, Database $database, callable $callback = null): bool
    {
        Authorization::disable();

        if($database->deleteDocument($document->getCollection(), $document->getId())) {
            Console::success('Deleted document "'.$document->getId().'" successfully');

            if(is_callable($callback)) {
                $callback($document);
            }

            return true;
        }
        else {
            Console::error('Failed to delete document: '.$document->getId());
            return false;
        }

        Authorization::reset();
    }

    protected function deleteByGroup(array $filters, Database $database, callable $callback = null)
    {
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $results = [];
        $sum = $limit;

        $executionStart = \microtime(true);
        
        while($sum === $limit) {
            $chunk++;

            Authorization::disable();

            $results = $database->find([
                'limit' => $limit,
                'offset' => 0,
                'orderField' => '$id',
                'orderType' => 'ASC',
                'orderCast' => 'string',
                'filters' => $filters,
            ]);

            Authorization::reset();

            $sum = count($results);

            Console::info('Deleting chunk #'.$chunk.'. Found '.$sum.' documents');

            foreach ($results as $document) {
                $this->deleteById($document, $database, $callback);
                $count++;
            }
        }

        $executionEnd = \microtime(true);

        Console::info("Deleted {$count} document by group in " . ($executionEnd - $executionStart) . " seconds");
    }

    /**
     * @return Database;
     */
    protected function getConsoleDB(): Database
    {
        global $register;

        if($this->consoleDB === null) {
            $this->consoleDB = new Database();
            $this->consoleDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
            $this->consoleDB->setNamespace('app_console'); // Main DB
            $this->consoleDB->setMocks(Config::getParam('collections', []));
        }

        return $this->consoleDB;
    }

    /**
     * @return Database;
     */
    protected function getProjectDB($projectId): Database
    {
        global $register;
        
        $projectDB = new Database();
        $projectDB->setAdapter(new RedisAdapter(new MySQLAdapter($register), $register));
        $projectDB->setNamespace('app_'.$projectId); // Main DB
        $projectDB->setMocks(Config::getParam('collections', []));

        return $projectDB;
    }
}
