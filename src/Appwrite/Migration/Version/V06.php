<?php

namespace Appwrite\Migration\Version;

use Utopia\CLI\Console;
use Utopia\Config\Config;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Migration\Migration;

class V06 extends Migration
{
    public function execute(): void
    {
        $project = $this->project;
        Console::log('Migrating project: ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');

        $this->forEachDocument([$this, 'fixDocument']);
    }

    protected function fixDocument(Document $document)
    {
        switch ($document->getAttribute('$collection')) {
            case Database::SYSTEM_COLLECTION_USERS:
                if ($document->getAttribute('password-update', null)) {
                    $document
                        ->setAttribute('passwordUpdate', $document->getAttribute('password-update', $document->getAttribute('passwordUpdate', '')))
                        ->removeAttribute('password-update');
                }
                if ($document->getAttribute('prefs', null)) {
                    //TODO: take care of filter ['json']
                }
                break;
            case Database::SYSTEM_COLLECTION_WEBHOOKS:
                if ($document->getAttribute('httpPass', null)) {
                    //TODO: take care of filter ['encrypt']
                }
                break;
            case Database::SYSTEM_COLLECTION_TASKS:
                if ($document->getAttribute('httpPass', null)) {
                    //TODO: take care of filter ['encrypt']
                }
                break;
            case Database::SYSTEM_COLLECTION_PROJECTS:
                $providers = Config::getParam('providers');

                foreach ($providers as $key => $provider) {
                    if ($document->getAttribute('usersOauth' . \ucfirst($key) . 'Secret', null)) {
                        //TODO: take care of filter ['encrypt]
                    }
                }
                break;
        }
        return $document;
    }
}
