<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class GraphQLBase extends Scope 
{
    use ProjectCustom;
    use SideClient;

    public function testListUsers()
    {

        /**
         * Test for SUCCESS
         */

        // $projectId = $this->getProject()['$id'];
        
        $projectId = '60394d47b252a';
        $collectionId = "6048c40b28392";
        
        $query = "
            query listDocuments(\$collectionId: String!){
                database_listDocuments (collectionId: \$collectionId) {
                    sum
                    documents 
                }
            }
        ";

        $variables = [
            'collectionId' => $collectionId
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $variables
        ];
        
        $response = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ]), $graphQLPayload);

        var_dump($response['headers']);
        var_dump($response['body']);
        $this->assertEquals($response['headers']['status-code'], 200);
        
    }
    
}