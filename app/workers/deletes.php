<?php

require_once __DIR__.'/../init.php';

cli_set_process_title('Deletes V1 Worker');

echo APP_NAME.' deletes worker v1 has started';

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Storage\Devices\Local;

class DeletesV1
{
    public $args = [];

    public function setUp()
    {
    }

    public function perform()
    {
        global $consoleDB, $request;

        $document = $this->args['document'];
        $document = new Document($document);
        
        switch ($document->getCollection()) {
            case Database::SYSTEM_COLLECTION_PROJECTS:
                $this->deleteProject($document);
                break;
            
            default:
                break;
        }
    }

    public function tearDown()
    {
        // ... Remove environment for this job
    }

    protected function deleteProject(Document $document)
    {
        global $consoleDB;

        // Delete all DBs
        $consoleDB->deleteNamespace($document->getId());
        $uploads = new Local(APP_STORAGE_UPLOADS.'/app-'.$document->getId());
        $cache = new Local(APP_STORAGE_CACHE.'/app-'.$document->getId());

        $uploads->delete($uploads->getRoot(), true);
        $cache->delete($cache->getRoot(), true);
    }
}
