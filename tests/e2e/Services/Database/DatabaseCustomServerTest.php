<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Client;

class DatabaseCustomServerTest extends Scope
{
    use DatabaseBase;
    use ProjectCustom;
    use SideServer;

    public function testDeleteCollection()
    {
        /**
         * Test for SUCCESS
         */

        // Create collection
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Actors',
            'read' => ['role:all'],
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
        ]);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertEquals($actors['body']['name'], 'Actors');
        $this->assertIsArray($actors['body']['$permissions']);
        $this->assertIsArray($actors['body']['$permissions']['read']);
        $this->assertIsArray($actors['body']['$permissions']['write']);
        $this->assertCount(1, $actors['body']['$permissions']['read']);
        $this->assertCount(2, $actors['body']['$permissions']['write']);

        // Add Documents to the collection
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Tom',
                'lastName' => 'Holland',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Samuel',
                'lastName' => 'Jackson',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);
       
        $this->assertEquals($document1['headers']['status-code'], 201);
        $this->assertEquals($document1['body']['$collection'], $actors['body']['$id']);
        $this->assertIsArray($document1['body']['$permissions']);
        $this->assertIsArray($document1['body']['$permissions']['read']);
        $this->assertIsArray($document1['body']['$permissions']['write']);
        $this->assertCount(1, $document1['body']['$permissions']['read']);
        $this->assertCount(1, $document1['body']['$permissions']['write']);
        $this->assertEquals($document1['body']['firstName'], 'Tom');
        $this->assertEquals($document1['body']['lastName'], 'Holland');

        $this->assertEquals($document2['headers']['status-code'], 201);
        $this->assertEquals($document2['body']['$collection'], $actors['body']['$id']);
        $this->assertIsArray($document2['body']['$permissions']);
        $this->assertIsArray($document2['body']['$permissions']['read']);
        $this->assertIsArray($document2['body']['$permissions']['write']);
        $this->assertCount(1, $document2['body']['$permissions']['read']);
        $this->assertCount(1, $document2['body']['$permissions']['write']);
        $this->assertEquals($document2['body']['firstName'], 'Samuel');
        $this->assertEquals($document2['body']['lastName'], 'Jackson');

        // Delete the actors collection
        $response = $this->client->call(Client::METHOD_DELETE, '/database/collections/'.$actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 204);
        $this->assertEquals($response['body'],"");

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/database/collections/'.$actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 404);
    }
}