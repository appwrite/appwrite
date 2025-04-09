<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;

trait ProjectsDevKeys
{
    /**
     * @depends testCreateProject
     * @group devKeys
     */
    public function testCreateProjectDevKey($data): array
    {
        /**
         * Test for SUCCESS
         */
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /** Create a second dev key */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Dev Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 36000)
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals('Dev Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */

        /** TEST expiry date is required */
        $res = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test'
        ]);

        $this->assertEquals(400, $res['headers']['status-code']);

        $data = array_merge($data, [
            'keyId' => $response['body']['$id'],
            'secret' => $response['body']['secret']
        ]);

        return $data;
    }


    /**
     * @depends testCreateProjectDevKey
     * @group devKeys
     */
    public function testListProjectDevKey($data): array
    {
        /**
         * Test for SUCCESS
         */

        /** List all dev keys */
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(2, $response['body']['total']);

        /** List dev keys with limit */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::limit(1)->toString()
            ]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        /** List dev keys with search */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'search' => 'Dev'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
        $this->assertEquals('Dev Key Test', $response['body']['devKeys'][0]['name']);

        /** List dev keys with querying `expire` */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [Query::lessThan('expire', (new \DateTime())->format('Y-m-d H:i:s'))->toString()]
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']); // No dev keys expired

        /**
         * Test for FAILURE
         */

        /** Test for search with invalid query */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => [
                Query::search('name', 'Invalid')->toString()
            ]
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `queries` param: Invalid query: Attribute not found in schema: name', $response['body']['message']);

        return $data;
    }


    /**
     * @depends testCreateProjectDevKey
     * @group devKeys
     */
    public function testGetProjectDevKey($data): array
    {
        /**
         * Test for SUCCESS
         */
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Dev Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProject
     * @group devKeys
     */
    public function testNoHostValidationWithDevKey($data): void
    {
        $id = $data['projectId'] ?? '';

        /** Create a dev key */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $devKey = $response['body']['secret'];

        /** Test oauth2 and get invalid `success` URL */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/google', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id
        ], [
            'success' => 'https://example.com',
            'failure' => 'https://example.com'
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `success` param: URL host must be one of: localhost, appwrite.io, *.appwrite.io', $response['body']['message']);

        /** Test oauth2 with devKey and now get oauth2 is disabled */
        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/google', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $devKey
        ], [
            'success' => 'https://example.com',
            'failure' => 'https://example.com'
        ]);
        $this->assertEquals(412, $response['headers']['status-code']);
        $this->assertEquals('This provider is disabled. Please enable the provider from your Appwrite console to continue.', $response['body']['message']);

        /** Test hostname in Magic URL */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ], [
            'userId' => ID::unique(),
            'email' => 'user@appwrite.io',
            'url' => 'https://example.com',
        ]);
        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertEquals('Invalid `url` param: URL host must be one of: localhost, appwrite.io, *.appwrite.io', $response['body']['message']);

        /** Test hostname in Magic URL with devKey */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/magic-url', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $devKey
        ], [
            'userId' => ID::unique(),
            'email' => 'user@appwrite.io',
            'url' => 'https://example.com',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
    }

    /**
     * @depends testCreateProjectDevKey
     * @group devKeys
     */
    public function testCorsWithDevKey($data): void
    {
        $projectId = $data['projectId'] ?? '';

        $id = $data['projectId'] ?? '';

        /** Create a dev key */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);

        $devKey = $response['body']['secret'];
        $origin = 'http://example.com';

        /**
         * Test CORS without Dev Key (should fail due to origin)
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => $origin,
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);

        $this->assertEquals(403, $response['headers']['status-code']);
        $this->assertNotEquals($origin, $response['headers']['access-control-allow-origin'] ?? null);
        $this->assertEquals('http://localhost', $response['headers']['access-control-allow-origin'] ?? null);


        /**
         * Test CORS with Dev Key (should bypass origin check)
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'origin' => $origin,
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-dev-key' => $devKey
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        $this->assertEquals('*', $response['headers']['access-control-allow-origin'] ?? null);
    }

    /**
     * @depends testCreateProject
     * @group devKeys
     */
    public function testNoRateLimitWithDevKey($data): void
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $devKey = $response['body']['secret'];

        for ($i = 0; $i < 10; $i++) {
            $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $id,
            ], [
                'email' => 'user@appwrite.io',
                'password' => 'password'
            ]);
            $this->assertEquals(401, $res['headers']['status-code']);
        }
        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $res['headers']['status-code']);

        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $devKey
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(401, $res['headers']['status-code']);


        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $response['body']['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $res['headers']['status-code']);


        /**
         * Test for FAILURE after expire
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/dev-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 5),
        ]);

        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $response['body']['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(401, $res['headers']['status-code']);

        sleep(5);

        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $response['body']['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $res['headers']['status-code']);
    }

    /**
     * @depends testCreateProjectDevKey
     * @group devKeys
     */
    public function testUpdateProjectDevKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/dev-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test Update',
            'expire' => DateTime::addSeconds(new \DateTime(), 360),
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        return $data;
    }

    /**
     * @depends testCreateProjectDevKey
     * @group devKeys
     */
    public function testDeleteProjectDevKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/dev-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /**
        * Get rate limit trying to use the deleted key
        */
        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-dev-key' => $data['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals(429, $res['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/dev-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);


        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/dev-keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }
}
