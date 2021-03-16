<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\SideServer;

class GraphQLBase extends Scope 
{
    use ProjectCustom;
    use SideServer;

    public function createKey(string $name, array $scopes): string {
        $projectId = $this->getProject()['$id'];
        $query = "
            mutation createKey(\$projectId: String!, \$name: String!, \$scopes: [Json]!){
                projects_createKey (projectId: \$projectId, name: \$name, scopes: \$scopes) {
                    id
                    name
                    scopes
                    secret
                }
            }
        ";
        
        $variables = [
            "projectId" => $projectId,
            "name" => $name,
            "scopes" => $scopes
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];

        $key = $this->client->call(Client::METHOD_POST, '/graphql', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console'
        ], $graphQLPayload);

        $this->assertEquals($key['headers']['status-code'], 201);
        $this->assertNull($key['body']['errors']);
        $this->assertIsArray($key['body']['data']);
        $this->assertIsArray($key['body']['data']['projects_createKey']);

        return $key['body']['data']['projects_createKey']['secret'];
    }
    
}