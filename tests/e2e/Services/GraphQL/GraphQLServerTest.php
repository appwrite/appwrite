<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class GraphQLServerTest extends Scope 
{
    use SideServer;
    use ProjectCustom;

    public function testCreateCollection() {
        $projectId = $this->getProject()['$id'];
        $key = $this->getProject()['apiKey'];
        $query = "
            mutation createCollection(\$name: String!, \$read: [Json]!, \$write: [Json]!, \$rules: [Json]!){
                database_createCollection (name: \$name, read : \$read, write: \$write, rules: \$rules) {
                    id
                    permissions {
                        read
                        write
                    }
                    name
                    dateCreated
                    dateUpdated
                    rules {
                        id
                        collection
                        type
                        key
                        label
                        default
                        array
                        required
                        list
                    }
                }
            }
        ";
        
        $actorsVariables = [
            'name' => 'Actors',
            'read' => ['*'],
            'write' => ['role:member', 'role:admin'],
            'rules' => [
                [
                    'label' => 'First Name',
                    'key' => 'firstName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
                [
                    'label' => 'Last Name',
                    'key' => 'lastName',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
            ],
        ];

        $graphQLPayload = [
            "query" => $query,
            "variables" => $actorsVariables
        ];
        
        $actors = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);


        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNull($actors['body']['errors']);
        $this->assertIsArray($actors['body']['data']);
        $this->assertIsArray($actors['body']['data']['database_createCollection']);

        $data = $actors['body']['data']['database_createCollection'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('permissions', $data);
        $this->assertEquals('Actors', $data['name']);
        $this->assertArrayHasKey('dateCreated', $data);
        $this->assertArrayHasKey('dateUpdated', $data);
        $this->assertArrayHasKey('rules', $data);
       
        $permissions = $data['permissions'];
        $this->assertIsArray($permissions);
        $this->assertArrayHasKey('read', $permissions);
        $this->assertArrayHasKey('write', $permissions);
        $read = $permissions['read'];
        $this->assertContains('*', $read);
        $write = $permissions['write'];
        $this->assertContains('role:member', $write);
        $this->assertContains('role:admin', $write);
        
        $rules = $data['rules'];
        $this->assertIsArray($rules);
        $this->assertCount(2, $rules);
        $firstRule = $rules[0];
        $this->assertArrayHasKey('id', $firstRule);
        $this->assertEquals('rules', $firstRule['collection']);
        $this->assertEquals('text', $firstRule['type']);
        $this->assertEquals('firstName', $firstRule['key']);
        $this->assertEquals('First Name', $firstRule['label']);
        $this->assertEquals('', $firstRule['default']);
        $this->assertEquals(false, $firstRule['array']);
        $this->assertEquals(true, $firstRule['required']);
        $this->assertEquals([], $firstRule['list']);
        $secondRule = $rules[1];
        $this->assertArrayHasKey('id', $secondRule);
        $this->assertEquals('rules', $secondRule['collection']);
        $this->assertEquals('text', $secondRule['type']);
        $this->assertEquals('lastName', $secondRule['key']);
        $this->assertEquals('Last Name', $secondRule['label']);
        $this->assertEquals('', $secondRule['default']);
        $this->assertEquals(false, $secondRule['array']);
        $this->assertEquals(true, $secondRule['required']);
        $this->assertEquals([], $secondRule['list']);

        $moviesVariables = [
            'name' => 'Movies',
            'read' => ['*'],
            'write' => ['role:member', 'role:admin'],
            'rules' => [
                [
                    'label' => 'Name',
                    'key' => 'name',
                    'type' => 'text',
                    'default' => '',
                    'required' => true,
                    'array' => false
                ],
                [
                    'label' => 'Release Year',
                    'key' => 'releaseYear',
                    'type' => 'numeric',
                    'default' => 0,
                    'required' => false,
                    'array' => false
                ],
                [
                    'label' => 'Actors',
                    'key' => 'actors',
                    'type' => 'document',
                    'default' => [],
                    'required' => false,
                    'array' => true,
                    'list' => [$data['id']],
                ],
            ],
        ];
        
        $graphQLPayload = [
            "query" => $query,
            "variables" => $moviesVariables
        ];
        
        $movies = $this->client->call(Client::METHOD_POST, '/graphql', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ]), $graphQLPayload);

        var_dump($movies);

        $this->assertEquals($movies['headers']['status-code'], 201);
        $this->assertNull($movies['body']['errors']);
        $this->assertIsArray($movies['body']['data']);
        $this->assertIsArray($movies['body']['data']['database_createCollection']);

        // $data = $movies['body']['data']['database_createCollection'];
        // $this->assertArrayHasKey('id', $data);
        // $this->assertArrayHasKey('permissions', $data);
        // $this->assertEquals('Movies', $data['name']);
        // $this->assertArrayHasKey('dateCreated', $data);
        // $this->assertArrayHasKey('dateUpdated', $data);
        // $this->assertArrayHasKey('rules', $data);
       
        // $permissions = $data['permissions'];
        // $this->assertIsArray($permissions);
        // $this->assertArrayHasKey('read', $permissions);
        // $this->assertArrayHasKey('write', $permissions);
        // $read = $permissions['read'];
        // $this->assertContains('*', $read);
        // $write = $permissions['write'];
        // $this->assertContains('role:member', $write);
        // $this->assertContains('role:admin', $write);
        
        // $rules = $data['rules'];
        // $this->assertIsArray($rules);
        // $this->assertCount(3, $rules);
        // $firstRule = $rules[0];
        // $this->assertArrayHasKey('id', $firstRule);
        // $this->assertEquals('rules', $firstRule['collection']);
        // $this->assertEquals('text', $firstRule['type']);
        // $this->assertEquals('name', $firstRule['key']);
        // $this->assertEquals('Name', $firstRule['label']);
        // $this->assertEquals('', $firstRule['default']);
        // $this->assertEquals(false, $firstRule['array']);
        // $this->assertEquals(true, $firstRule['required']);
        // $this->assertEquals([], $firstRule['list']);
        // $secondRule = $rules[1];
        // $this->assertArrayHasKey('id', $secondRule);
        // $this->assertEquals('rules', $secondRule['collection']);
        // $this->assertEquals('numeric', $secondRule['type']);
        // $this->assertEquals('releaseYear', $secondRule['key']);
        // $this->assertEquals('Release Year', $secondRule['label']);
        // $this->assertEquals(0, $secondRule['default']);
        // $this->assertEquals(false, $secondRule['array']);
        // $this->assertEquals(false, $secondRule['required']);
        // $this->assertEquals([], $secondRule['list']);
        // $thirdRule = $rules[2];
        // $this->assertArrayHasKey('id', $thirdRule);
        // $this->assertEquals('rules', $thirdRule['collection']);
        // $this->assertEquals('document', $thirdRule['type']);
        // $this->assertEquals('actors', $thirdRule['key']);
        // $this->assertEquals('Actors', $thirdRule['label']);
        // $this->assertEquals([], $thirdRule['default']);
        // $this->assertEquals(true, $thirdRule['array']);
        // $this->assertEquals(false, $thirdRule['required']);
        // $this->assertEquals([$actors['body']['data']['$id']], $thirdRule['list']);

        // return $data;
    }


    //  /**
    //  * @depends testCreateCollection
    //  */
    // public function testAddDocuments(array $data) {
        
    //     $response = $this->client->call(Client::METHOD_DELETE, '/database/collections/'.$data['id'], [
    //         'content-type' => 'application/json',
    //         'x-appwrite-project' => $this->getProject()['$id'],
    //         'x-appwrite-key' => $this->getProject()['apiKey']
    //     ]);

    // }
}