<?php

namespace Appwrite\Migration\Version;

use Utopia\CLI\Console;
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
                break;
        }
        return $document;
    }
}
