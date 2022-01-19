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
            case 'projects':
                /**
                 * Bump Project version number.
                 */
                $document->setAttribute('version', '0.13.0');

                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'users':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'email', 'name'], $document));
                }

                break;

            case 'teams':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'files':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name'], $document));
                }

                break;

            case 'functions':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'name', 'runtime'], $document));
                }

                break;

            case 'tags':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'command'], $document));
                }

                break;

            case 'executions':
                /**
                 * Populate search string from Migration to 0.12.
                 */
                if (empty($document->getAttribute('search'))) {
                    $document->setAttribute('search', $this->buildSearchAttribute(['$id', 'functionId'], $document));
                }

                break;
        }

        return $document;
    }

    /**
     * Builds a search string for a fulltext index.
     *
     * @param array $values
     * @param Document $document
     * @return string
     */
    private function buildSearchAttribute(array $values, Document $document): string
    {
        $values = array_filter(array_map(fn (string $value) => $document->getAttribute($value) ?? '', $values));

        return implode(' ', $values);
    }
}
