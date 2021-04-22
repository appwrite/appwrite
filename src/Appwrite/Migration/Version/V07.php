<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use Appwrite\Database\Document;

class V07 extends Migration
{
    public function execute(): void
    {
        $db = $this->db;
        $project = $this->project;
        Console::log('Migrating project: ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');

        $this->forEachDocument([$this, 'fixDocument']);
    }

    protected function fixDocument(Document $document)
    {
        $providers = Config::getParam('providers');

        switch ($document->getAttribute('$collection')) {
            case Database::SYSTEM_COLLECTION_USERS:
                /**
                 * Remove deprecated OAuth2 properties in the Users Documents.
                 */
                foreach ($providers as $key => $provider) {
                    if (!empty($document->getAttribute('oauth2' . \ucfirst($key)))) {
                        $document->removeAttribute('oauth2' . \ucfirst($key));
                    }

                    if (!empty($document->getAttribute('oauth2' . \ucfirst($key) . 'AccessToken'))) {
                        $document->removeAttribute('oauth2' . \ucfirst($key) . 'AccessToken');
                    }
                }
                /**
                 * Invalidate all Login Tokens, since they can't be migrated to the new structure.
                 * Reason for it is the missing distinction between E-Mail and OAuth2 tokens.
                 */
                $tokens = array_filter($document->getAttribute('tokens', []), function ($token) {
                    return ($token->getAttribute('type') != Auth::TOKEN_TYPE_LOGIN);
                });
                $document->setAttribute('tokens', array_values($tokens));

                /**
                 * Remove deprecated user status 0.
                 */
                if ($document->getAttribute('status') === 0) {
                    $document->setAttribute('status', 1);
                }

                break;
        }

        foreach ($document as &$attr) { // Handle child documents
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
