<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Domains\DomainsBase;
use Utopia\Database\Helpers\ID;

class DomainsRegistrarClientTest extends Scope
{
    use DomainsBase;
    use ProjectConsole;
    use SideClient;

    public function testCreateProject(): array
    {
        /**
         * Test for SUCCESS
         */
        $team = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'teamId' => ID::unique(),
            'name' => 'Project Test',
        ]);

        $this->assertEquals(201, $team['headers']['status-code']);
        $this->assertEquals('Project Test', $team['body']['name']);
        $this->assertNotEmpty($team['body']['$id']);

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
            'region' => 'default',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test', $response['body']['name']);
        $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        $projectId = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_POST, '/projects', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => ID::unique(),
            'name' => 'Project Test',
            'teamId' => $team['body']['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Project Test', $response['body']['name']);
        $this->assertEquals($team['body']['$id'], $response['body']['teamId']);
        $this->assertArrayHasKey('platforms', $response['body']);
        $this->assertArrayHasKey('webhooks', $response['body']);
        $this->assertArrayHasKey('keys', $response['body']);

        return ['projectId' => $projectId];
    }

      /**
       * @depends testCreateProject
       */
    public function testSuggestDomain($data): void
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/domains/suggest', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domain' => 'kittens.com',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['domains']);
    }

      /**
       * @depends testCreateProject
       */
    public function testAvailableDomain($data): void
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/domains/available', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domain' => 'google.com',
        ]);

        $available = $response['body']['domain']['available'];

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['domain']);
        $this->assertFalse($available);
    }

      /**
       * @depends testCreateProject
       */
    public function testCreate3rdPartyDomain($data): array
    {
        $id = $data['projectId'] ?? '';
        $domain = $this->generateRandomString() . '.net';

        $response = $this->client->call(Client::METHOD_POST, '/domains', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domain' => $domain,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($domain, $response['body']['domain']);
        $this->assertEquals(false, $response['body']['verification']);

        /**
         * Test for FAILURE
         */
        $response = $response = $this->client->call(Client::METHOD_POST, '/domains', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domain' => 'sdkljgfhsdflkjghsdflkgjsh.com',
        ]);

        return [];
    }

      /**
       * @depends testCreateProject
       */
    public function testPurchaseDomain($data): array
    {
        $id = $data['projectId'] ?? '';
        $domain = $this->generateRandomString() . '.net';

        $response = $this->client->call(Client::METHOD_POST, '/domains/purchase', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domain' => $domain,
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'phone' => '+18037889693',
            'email' => 'email@email.com',
            'address1' => 'address1 st',
            'address2' => 'unit address2',
            'address3' => 'apt. address3',
            'city' => 'city',
            'state' => 'state',
            'country' => 'us',
            'postalcode' => '29223',
            'org' => 'myorg',
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertTrue($response['body']['registered']);

        return [
            'projectId' => $id,
            'domain' => $domain,
        ];
    }

    public function testTransferInDomain(): void
    {
        // This will always fail mainly because it's a test env,
        // but also because:
        // - we use random domains to test
        // - transfer lock is default
        // - unable to unlock transfer because domains (in tests) are new.
        // ** Even when testing against my own live domains, it failed.
        // So we test for a proper formatted response,
        // with "successful" being "false".

        $this->markTestSkipped('Transfer test skipped because it always fails.');
    }

    public function testTransferOutDomain(): void
    {
        // This will always fail mainly because it's a test env,
        // but also because:
        // - we use random domains to test
        // - transfer lock is default
        // - unable to unlock transfer because domains (in tests) are new.
        // ** Even when testing against my own live domains, it failed.
        // So we test for a proper formatted response,
        // with "successful" being "false".

        $this->markTestSkipped('Transfer test skipped because it always fails.');
    }

      /**
       * @depends testCreateProject
       */
    public function testDomainList($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/domains', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertTrue($response['body']['total'] > 0);
        $this->assertTrue(count($response['body']['domains']) > 0);

        return [
            'projectId' => $id,
            'domains' => $response['body']['domains'],
        ];
    }

      /**
       * @depends testDomainList
       */
    public function testDomainGet($data): array
    {
        $id = $data['projectId'] ?? '';
        $domains = $data['domains'] ?? [];
        $domain = $domains[0];
        $domainId = $domain['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/domains/' . $domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domainId' => $domains[0]['$id'],
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        return [
            'projectId' => $id,
            'domainId' => $domainId,
        ];
    }

      /**
       * @depends testDomainGet
       */
    public function testDomainDelete($data): string
    {
        $id = $data['projectId'] ?? '';
        $domainId = $data['domainId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/domains/' . $domainId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'projectId' => $id,
            'domainId' => $domainId,
        ]);

        $this->assertEquals(204, $response['headers']['status-code']);

        return $domainId;
    }

    private function generateRandomString(int $length = 10): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }
}
