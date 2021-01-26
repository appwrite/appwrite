<?php

namespace Appwrite\Migration\Version;


use Utopia\App;
use Utopia\CLI\Console;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Migration\Migration;
use Appwrite\OpenSSL\OpenSSL;

class V06 extends Migration
{
    public function execute(): void
    {
        $project = $this->project;
        Console::log('Migrating project: ' . $project->getAttribute('name') . ' (' . $project->getId() . ')');

        $this->projectDB->disableFilters();
        $this->forEachDocument([$this, 'fixDocument']);
        $this->projectDB->enableFilters();
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
            case Database::SYSTEM_COLLECTION_KEYS:
                if ($document->getAttribute('secret', null)) {
                    $json = \json_decode($document->getAttribute('secret'));
                    if ($json->{'data'} || $json->{'method'} || $json->{'iv'} || $json->{'tag'} || $json->{'version'})
                    {
                        Console::log('Secret already encrypted. Skipped: ' . $document->getId());
                        break;
                    }

                    $key = App::getEnv('_APP_OPENSSL_KEY_V1');
                    $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
                    $tag = null;

                    $document->setAttribute('secret', json_encode([
                        'data' => OpenSSL::encrypt($document->getAttribute('secret'), OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
                        'method' => OpenSSL::CIPHER_AES_128_GCM,
                        'iv' => bin2hex($iv),
                        'tag' => bin2hex($tag),
                        'version' => '1',
                    ]));
                }
                break;
        }
        return $document;
    }
}
