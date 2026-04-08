<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

/**
 * Trait that skips the entire test class when the DocumentsDB backend
 * (MongoDB) is not reachable.  Uses a single probe request per
 * class, cached in a static flag.
 */
trait RequiresDocumentsDB
{
    private static ?bool $documentsDBAvailable = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$documentsDBAvailable === null) {
            $response = $this->client->call(Client::METHOD_POST, '/documentsdb', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'databaseId' => ID::custom('documentsdb-probe'),
                'name' => 'Probe',
            ]);
            self::$documentsDBAvailable = $response['headers']['status-code'] < 500;

            if (self::$documentsDBAvailable) {
                $this->client->call(Client::METHOD_DELETE, '/documentsdb/documentsdb-probe', [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }
        }

        if (!self::$documentsDBAvailable) {
            $this->markTestSkipped('DocumentsDB backend (MongoDB) is not available in this CI environment.');
        }
    }
}
