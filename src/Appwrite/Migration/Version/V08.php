<?php

namespace Appwrite\Migration\Version;

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Migration\Migration;
use Utopia\CLI\Console;

class V08 extends Migration
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
            /**
             * Rename env attribute to runtime.
             */
            case Database::SYSTEM_COLLECTION_FUNCTIONS:
                if ($document->isSet('env')) {
                    $document
                        ->setAttribute('runtime', $document->getAttribute('env', $document->getAttribute('env', '')))
                        ->removeAttribute('env');
                }

                break;
            /**
             * Add version reference to database.
             */
            case Database::SYSTEM_COLLECTION_PROJECTS:
                    $document->setAttribute('version', '0.9.0');

                break;
        }

        foreach ($document as &$attr) {
            if ($attr instanceof Document) {
                $attr = $this->fixDocument($attr);
            }

            if (\is_array($attr)) {
                foreach ($attr as &$child) {
                    if ($child instanceof Document) {
                        $child = $this->fixDocument($child);
                    }
                }
            }
        }

        return $document;
    }
}
