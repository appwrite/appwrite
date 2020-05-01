<?php

namespace Tests\E2E\Services\Health;

use Tests\E2E\Client;

trait HealthBase
{
    public function testHTTPSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testDBSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/db', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testCacheSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/db', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testTimeSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/time', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['remote']);
        $this->assertIsInt($response['body']['local']);
        $this->assertNotEmpty($response['body']['remote']);
        $this->assertNotEmpty($response['body']['local']);
        $this->assertLessThan(10, $response['body']['diff']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testWebhooksSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/quque/webhooks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(50, $response['body']['size']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testTasksSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/quque/tasks', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(50, $response['body']['size']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testLogsSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/quque/logs', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(50, $response['body']['size']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testUsageSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/quque/usage', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(50, $response['body']['size']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testCertificatesSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/quque/certificates', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertIsInt($response['body']['size']);
        $this->assertLessThan(50, $response['body']['size']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testStorageLocalSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/storage/local', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('OK', $response['body']['status']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testStorageAntiVirusSuccess():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/health/storage/anti-virus', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), []);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('online', $response['body']['status']);
        $this->assertStringStartsWith('ClamAV ', $response['body']['version']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }
}