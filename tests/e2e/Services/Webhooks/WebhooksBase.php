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
            'collectionId' => 'unique()',
            'name' => 'Actors',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);
        
        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertNotEmpty($actors['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.collections.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), true);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['name'], 'Actors');
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertCount(1, $webhook['data']['$read']);
        $this->assertCount(1, $webhook['data']['$write']);

        return array_merge(['actorsId' => $actors['body']['$id']]);
    }

    /**
     * @depends testCreateCollection
     */
    public function testCreateAttributes(array $data): array
    {
        $firstName = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'firstName',
            'type' => 'string',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'lastName',
            'type' => 'string',
            'size' => 256,
            'required' => true,
        ]);

        $this->assertEquals($firstName['headers']['status-code'], 201);
        $this->assertEquals($firstName['body']['$collection'], $data['actorsId']);
        $this->assertEquals($firstName['body']['$id'], 'firstName');
        $this->assertEquals($lastName['headers']['status-code'], 201);
        $this->assertEquals($lastName['body']['$collection'], $data['actorsId']);
        $this->assertEquals($lastName['body']['$id'], 'lastName');

        // wait for database worker to kick in
        sleep(10);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.attributes.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['$id'], 'lastName');
        
        // TODO@kodumbeats test webhook for removing attribute

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testCreateDocument(array $data): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['actorsId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'firstName' => 'Chris',
                'lastName' => 'Evans',
                 
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.documents.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris');
        $this->assertEquals($webhook['data']['lastName'], 'Evans');
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertCount(1, $webhook['data']['$read']);
        $this->assertCount(1, $webhook['data']['$write']);

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
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertNotEmpty($document['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'database.documents.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Chris1');
        $this->assertEquals($webhook['data']['lastName'], 'Evans2');
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertCount(1, $webhook['data']['$read']);
        $this->assertCount(1, $webhook['data']['$write']);

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
            'documentId' => 'unique()',
            'data' => [
                'firstName' => 'Bradly',
                'lastName' => 'Cooper',
                 
            ],
            'read' => ['role:all'],
            'write' => ['role:all'],
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
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals($webhook['data']['firstName'], 'Bradly');
        $this->assertEquals($webhook['data']['lastName'], 'Cooper');
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertCount(1, $webhook['data']['$read']);
        $this->assertCount(1, $webhook['data']['$write']);

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
            'fileId' => 'unique()',
            'file' => new CURLFile(realpath(__DIR__ . '/../../../resources/logo.png'), 'image/png', 'logo.png'),
            'read' => ['role:all'],
            'write' => ['role:all'],
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
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
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
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($file['headers']['status-code'], 200);
        $this->assertNotEmpty($file['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'storage.files.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
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
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertIsArray($webhook['data']['$read']);
        $this->assertIsArray($webhook['data']['$write']);
        $this->assertEquals($webhook['data']['name'], 'logo.png');
        $this->assertIsInt($webhook['data']['dateCreated'], 'logo.png');
        $this->assertNotEmpty($webhook['data']['signature']);
        $this->assertEquals($webhook['data']['mimeType'], 'image/png');
        $this->assertEquals($webhook['data']['sizeOriginal'], 47218);
        
        return $data;
    }

    public function testCreateTeam(): array
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => 'unique()',
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'teams.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Arsenal', $webhook['data']['name']);
        $this->assertGreaterThan(-1, $webhook['data']['sum']);
        $this->assertIsInt($webhook['data']['sum']);
        $this->assertIsInt($webhook['data']['dateCreated']);

        /**
         * Test for FAILURE
         */
        return ['teamId' => $team['body']['$id']];
    }

    /**
     * @depends testCreateTeam
     */
    public function testUpdateTeam($data): array
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_PUT, '/teams/'.$data['teamId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Demo New'
        ]);

        $this->assertEquals(200, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'teams.update');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Demo New', $webhook['data']['name']);
        $this->assertGreaterThan(-1, $webhook['data']['sum']);
        $this->assertIsInt($webhook['data']['sum']);
        $this->assertIsInt($webhook['data']['dateCreated']);

        /**
         * Test for FAILURE
         */
        return ['teamId' => $team['body']['$id']];
    }

    public function testDeleteTeam(): array
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => 'unique()',
            'name' => 'Chelsea'
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $team = $this->client->call(Client::METHOD_DELETE, '/teams/'.$team['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'teams.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertEquals('Chelsea', $webhook['data']['name']);
        $this->assertGreaterThan(-1, $webhook['data']['sum']);
        $this->assertIsInt($webhook['data']['sum']);
        $this->assertIsInt($webhook['data']['dateCreated']);

        /**
         * Test for FAILURE
         */
        return [];
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data): array
    {
        $teamUid = $data['teamId'] ?? '';
        $email = uniqid().'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'membershipId' => 'unique()',
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);

        $lastEmail = $this->getLastEmail();

        $secret = substr($lastEmail['text'], strpos($lastEmail['text'], '&secret=', 0) + 8, 256);
        $membershipUid = substr($lastEmail['text'], strpos($lastEmail['text'], '?membershipId=', 0) + 14, 13);
        $userUid = substr($lastEmail['text'], strpos($lastEmail['text'], '&userId=', 0) + 8, 13);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'teams.memberships.create');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertIsInt($webhook['data']['joined']);
        $this->assertEquals(('server' === $this->getSide()), $webhook['data']['confirm']);

        /**
         * Test for FAILURE
         */
        return [
            'teamId' => $teamUid,
            'secret' => $secret,
            'membershipId' => $membershipUid,
            'userId' => $webhook['data']['userId'],
        ];
    }

    /**
     * @depends testCreateTeam
     */
    public function testDeleteTeamMembership($data): array
    {
        $teamUid = $data['teamId'] ?? '';
        $email = uniqid().'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams/'.$teamUid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'membershipId' => 'unique()',
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertNotEmpty($team['body']['$id']);
        
        $team = $this->client->call(Client::METHOD_DELETE, '/teams/'.$teamUid.'/memberships/'.$team['body']['$id'], array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $team['headers']['status-code']);

        $webhook = $this->getLastRequest();

        $this->assertEquals($webhook['method'], 'POST');
        $this->assertEquals($webhook['headers']['Content-Type'], 'application/json');
        $this->assertEquals($webhook['headers']['User-Agent'], 'Appwrite-Server vdev. Please report abuse at security@appwrite.io');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Event'], 'teams.memberships.delete');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Signature'], 'not-yet-implemented');
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Id'] ?? '', $this->getProject()['webhookId']);
        $this->assertEquals($webhook['headers']['X-Appwrite-Webhook-Project-Id'] ?? '', $this->getProject()['$id']);
        $this->assertEquals(empty($webhook['headers']['X-Appwrite-Webhook-User-Id'] ?? ''), ('server' === $this->getSide()));
        $this->assertNotEmpty($webhook['data']['$id']);
        $this->assertNotEmpty($webhook['data']['userId']);
        $this->assertNotEmpty($webhook['data']['teamId']);
        $this->assertCount(2, $webhook['data']['roles']);
        $this->assertIsInt($webhook['data']['joined']);
        $this->assertEquals(('server' === $this->getSide()), $webhook['data']['confirm']);

        /**
         * Test for FAILURE
         */
        return [];
    }
}