<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\CLI\Console;
use Utopia\Database\Document;

class V12 extends Migration
{
    public function execute(): void
    {
        Console::log('Migrating project: ' . $this->project->getAttribute('name') . ' (' . $this->project->getId() . ')');

        $this->forEachDocument([$this, 'fixDocument']);
    }

    protected function fixDocument(Document $document)
    {
        switch ($document->getCollection()) {
            /**
             * Bump Project version number.
             */
            case 'projects':
                    $document->setAttribute('version', '0.13.0');

                break;
        }

        return $document;
    }
}
