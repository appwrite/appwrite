<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Client;
use Utopia\Database\DateTime;

trait ProjectsDevelopmentKeys
{
    /**
     * @depends testCreateProject
     * @group developmentKeys
     */
    public function testCreateProjectDevelopmentKey($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/development-keys', array_merge([
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
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /** TEST expiry date is required */
        $res = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/development-keys', array_merge([
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
     * @depends testCreateProjectDevelopmentKey
     * @group developmentKeys
     */
    public function testListProjectDevelopmentKey($data): array
    {
        $id = $data['projectId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/development-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);


        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);

        return $data;
    }


    /**
     * @depends testCreateProjectDevelopmentKey
     * @group developmentKeys
     */
    public function testGetProjectDevelopmentKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/development-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test', $response['body']['name']);
        $this->assertNotEmpty($response['body']['secret']);
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/development-keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateProject
     * @group developmentKeys
     */
    public function testNoRateLimitWithDevelopmentKey($data): void
    {
        $id = $data['projectId'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/development-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), 3600),
        ]);

        $developmentKey = $response['body']['secret'];

        //
        for($i = 0; $i < 11; $i++) {
            $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $id,
            ], [
                'email' => 'user@appwrite.io',
                'password' => 'password'
            ]);
        }
        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals('429', $res['headers']['status-code']);

        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-development-key' => $developmentKey
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals('401', $res['headers']['status-code']);


        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/projects/' . $id . '/development-keys', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Key Test',
            'expire' => DateTime::addSeconds(new \DateTime(), -3600),
        ]);

        $res = $this->client->call(Client::METHOD_POST, '/account/sessions/email', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $id,
            'x-appwrite-development-key' => $response['body']['secret']
        ], [
            'email' => 'user@appwrite.io',
            'password' => 'password'
        ]);
        $this->assertEquals('429', $res['headers']['status-code']);
    }


    /**
     * @depends testCreateProjectDevelopmentKey
     * @group developmentKeys
     */
    public function testUpdateProjectDevelopmentKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_PUT, '/projects/' . $id . '/development-keys/' . $keyId, array_merge([
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
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/development-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals($keyId, $response['body']['$id']);
        $this->assertEquals('Key Test Update', $response['body']['name']);
        $this->assertArrayHasKey('sdks', $response['body']);
        $this->assertEmpty($response['body']['sdks']);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertEmpty($response['body']['accessedAt']);

        return $data;
    }

    /**
     * @depends testCreateProjectDevelopmentKey
     * @group developmentKeys
     */
    public function testDeleteProjectDevelopmentKey($data): array
    {
        $id = $data['projectId'] ?? '';
        $keyId = $data['keyId'] ?? '';

        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/development-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        $response = $this->client->call(Client::METHOD_GET, '/projects/' . $id . '/development-keys/' . $keyId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_DELETE, '/projects/' . $id . '/development-keys/error', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(404, $response['headers']['status-code']);

        return $data;
    }
}
