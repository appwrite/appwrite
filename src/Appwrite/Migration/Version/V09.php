<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Database\Database;
use Appwrite\Database\Document;

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
            case Database::SYSTEM_COLLECTION_USERS:
                /**
                 * Remove deprecated user status 0 and replace with boolean.
                 */
                if ($document->getAttribute('status') === 0 || $document->getAttribute('status') === 1) {
                    $document->setAttribute('status', true);
                }
                if ($document->getAttribute('status') === 2) {
                    $document->setAttribute('status', false);
                }
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
