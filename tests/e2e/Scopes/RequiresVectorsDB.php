<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;

/**
 * Trait that skips the entire test class when the VectorsDB backend
 * (PostgreSQL) is not reachable.  Uses a single probe request per
 * class, cached in a static flag.
 */
trait RequiresVectorsDB
{
    private static ?bool $vectorsDBAvailable = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$vectorsDBAvailable === null) {
            $response = $this->client->call(Client::METHOD_POST, '/vectorsdb', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey'],
            ], [
                'databaseId' => ID::custom('vectorsdb-probe'),
                'name' => 'Probe',
            ]);
            self::$vectorsDBAvailable = $response['headers']['status-code'] < 500;

            if (self::$vectorsDBAvailable) {
                $this->client->call(Client::METHOD_DELETE, '/vectorsdb/vectorsdb-probe', [
                    'content-type' => 'application/json',
                    'x-appwrite-project' => $this->getProject()['$id'],
                    'x-appwrite-key' => $this->getProject()['apiKey'],
                ]);
            }
        }

        if (!self::$vectorsDBAvailable) {
            $this->markTestSkipped('VectorsDB backend (PostgreSQL) is not available in this CI environment.');
        }
    }
}
