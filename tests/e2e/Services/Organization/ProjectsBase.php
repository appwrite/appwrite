<?php

namespace Tests\E2E\Services\Organization;

use Tests\E2E\Client;
use Utopia\Database\Helpers\ID;
use Utopia\System\System;

trait ProjectsBase
{
    public function testCreateProject(): void
    {
        $response = $this->client->call(Client::METHOD_POST, '/v1/organization/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Organization Project Test',
            'region' => System::getEnv('_APP_REGION', 'default'),
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Organization Project Test', $response['body']['name']);
    }
}
