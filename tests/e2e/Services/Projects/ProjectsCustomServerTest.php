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
        $testId = \uniqid();

        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-mode' => 'admin',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]);

        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'domain' => $testId . '-api.appwrite.test',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'resourceType' => 'api',
            'domain' => $testId . '-abc.test.io',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);

        // duplicate rule
        $response2 = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'domain' => $testId . '-abc.test.io',
        ]);

        $this->assertEquals(409, $response2['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_DELETE, '/proxy/rules/' . $response['body']['$id'], $headers);

        $this->assertEquals(204, $response['headers']['status-code']);

        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'domain' => $functionsDomain,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);


        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');

        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
            'domain' => $sitesDomain,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // prevent functions domain
        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/function', $headers, [
            'domain' => $functionsDomain,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        // prevent sites domain
        $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/site', $headers, [
            'domain' => $sitesDomain,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        $deniedDomains = [
            'sites.localhost',
            'functions.localhost',
            'appwrite.test',
            'localhost'
        ];

        foreach ($deniedDomains as $deniedDomain) {
            $response = $this->client->call(Client::METHOD_POST, '/proxy/rules/api', $headers, [
                'domain' => $deniedDomain,
            ]);

            $this->assertEquals(400, $response['headers']['status-code']);
        }
    }
}
