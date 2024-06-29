<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Utopia\System\System;

class ProjectsCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    // Domains

    public function testCreateProjectRule()
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules', $headers, [
            'resourceType' => 'api',
            'domain' => 'api.appwrite.test',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/proxy/rules/' . $response['body']['$id'], $headers);

        $this->assertEquals(204, $response['headers']['status-code']);

        // prevent functions domain
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules', $headers, [
            'resourceType' => 'api',
            'domain' => $functionsDomain,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }
}
