<?php

namespace Tests\E2E\Services\Webhooks;

use CURLFile;
use Tests\E2E\Client;

trait WebhooksBase
{
    public function testCreateCollection(): array
    {
        /**
         * Test for SUCCESS
         */
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Actors',
            'read' => ['*'],
            'write' => ['*'],
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
        $this->assertNotEmpty($actors['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.collections.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'Actors');
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertIsArray($webhook['data']['$permissions']['read']);
        $this->assertIsArray($webhook['data']['$permissions']['write']);
        $this->assertCount(1, $webhook['data']['$permissions']['read']);
        $this->assertCount(1, $webhook['data']['$permissions']['write']);
        $this->assertCount(2, $webhook['data']['rules']);

        return array_merge(['actorsId' => $actors['body']['$id']]);
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateDocument(array $data): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
                 
            ],
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.documents.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris');
        $this->assertEquals($webhook['data']['lastName'], 'Evans');
        $this->assertIsArray($webhook['data']['$permissions']['read']);
        $this->assertIsArray($webhook['data']['$permissions']['write']);
        $this->assertCount(1, $webhook['data']['$permissions']['read']);
        $this->assertCount(1, $webhook['data']['$permissions']['write']);

        $data['documentId'] = $document['body']['$id'];

        return $data;
    }

    /**
     * @depends testCreateDocument
     */
    public function testUpdateDocument(array $data): array
    {
        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['actorsId'] . '/documents/'.$data['documentId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Chris1',
                'lastName' => 'Evans2',
            ],
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.documents.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris1');
        $this->assertEquals($webhook['data']['lastName'], 'Evans2');
        $this->assertIsArray($webhook['data']['$permissions']['read']);
        $this->assertIsArray($webhook['data']['$permissions']['write']);
        $this->assertCount(1, $webhook['data']['$permissions']['read']);
        $this->assertCount(1, $webhook['data']['$permissions']['write']);

        return $data;
    }

    /**
     * @depends testCreateCollection
     */
    public function testDeleteDocument(array $data): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'firstName' => 'Bradly',
                'lastName' => 'Cooper',
                 
            ],
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNotEmpty($document['body']['$id']);

        $document = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['actorsId'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 204);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.documents.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Bradly');
        $this->assertEquals($webhook['data']['lastName'], 'Cooper');
        $this->assertIsArray($webhook['data']['$permissions']['read']);
        $this->assertIsArray($webhook['data']['$permissions']['write']);
        $this->assertCount(1, $webhook['data']['$permissions']['read']);
        $this->assertCount(1, $webhook['data']['$permissions']['write']);

        return $data;
    }

    public function testCreateFile(): array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_POST, '/storage/files', array_merge([
            'content-type' => 'multipart/form-data',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['*'],
            'write' => ['*'],
            'folderId' => 'xyz',
        ]);

        $this->assertEquals($file['headers']['status-code'], 201);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);

        /**
         * Test for FAILURE
         */
        return ['fileId' => $file['body']['$id']];
    }
    
    /**
     * @depends testCreateFile
     */
    public function testUpdateFile(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_PUT, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'read' => ['*'],
            'write' => ['*'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 200);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
        
        return $data;
    }
    
    /**
     * @depends testUpdateFile
     */
    public function testDeleteFile(array $data): array
    {
        /**
         * Test for SUCCESS
         */
        $file = $this->client->call(Client::METHOD_DELETE, '/storage/files/' . $data['fileId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $file['headers']['status-code']);
        $this->assertEmpty($file['body']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-Userid'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$permissions']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
        
        return $data;
    }
}