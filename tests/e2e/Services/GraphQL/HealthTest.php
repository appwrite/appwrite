<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class HealthTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use Base;

    public function testGetHTTPHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_HTTP_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $httpHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($httpHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $httpHealth['body']);
        $httpHealth = $httpHealth['body']['data']['healthGet'];
        $this->assertIsArray($httpHealth);

        return $httpHealth;
    }

    public function testGetDBHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_DB_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $dbHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($dbHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $dbHealth['body']);
        $dbHealth = $dbHealth['body']['data']['healthGetDB'];
        $this->assertIsArray($dbHealth);

        return $dbHealth;
    }

    public function testGetCacheHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_CACHE_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $cacheHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($cacheHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $cacheHealth['body']);
        $cacheHealth = $cacheHealth['body']['data']['healthGetCache'];
        $this->assertIsArray($cacheHealth);

        return $cacheHealth;
    }

    public function testGetTimeHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_TIME_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $timeHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($timeHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $timeHealth['body']);
        $timeHealth = $timeHealth['body']['data']['healthGetTime'];
        $this->assertIsArray($timeHealth);

        return $timeHealth;
    }

    public function testGetWebhooksQueueHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_WEBHOOKS_QUEUE_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $webhooksQueueHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($webhooksQueueHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $webhooksQueueHealth['body']);
        $webhooksQueueHealth = $webhooksQueueHealth['body']['data']['healthGetQueueWebhooks'];
        $this->assertIsArray($webhooksQueueHealth);

        return $webhooksQueueHealth;
    }

    public function testGetLogsQueueHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_LOGS_QUEUE_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $logsQueueHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($logsQueueHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $logsQueueHealth['body']);
        $logsQueueHealth = $logsQueueHealth['body']['data']['healthGetQueueLogs'];
        $this->assertIsArray($logsQueueHealth);

        return $logsQueueHealth;
    }

    public function testGetCertificatesQueueHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_CERTIFICATES_QUEUE_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $certificatesQueueHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($certificatesQueueHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $certificatesQueueHealth['body']);
        $certificatesQueueHealth = $certificatesQueueHealth['body']['data']['healthGetQueueCertificates'];
        $this->assertIsArray($certificatesQueueHealth);

        return $certificatesQueueHealth;
    }

    public function testGetFunctionsQueueHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_FUNCTION_QUEUE_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $functionsQueueHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($functionsQueueHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $functionsQueueHealth['body']);
        $functionsQueueHealth = $functionsQueueHealth['body']['data']['healthGetQueueFunctions'];
        $this->assertIsArray($functionsQueueHealth);

        return $functionsQueueHealth;
    }

    public function testGetLocalStorageHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_LOCAL_STORAGE_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $localStorageHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($localStorageHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $localStorageHealth['body']);
        $localStorageHealth = $localStorageHealth['body']['data']['healthGetStorageLocal'];
        $this->assertIsArray($localStorageHealth);

        return $localStorageHealth;
    }

    public function testGetAntiVirusHealth()
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$GET_ANITVIRUS_HEALTH);
        $graphQLPayload = [
            'query' => $query,
        ];

        $antiVirusHealth = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($antiVirusHealth['body']['data']);
        $this->assertArrayNotHasKey('errors', $antiVirusHealth['body']);
        $antiVirusHealth = $antiVirusHealth['body']['data']['healthGetAntivirus'];
        $this->assertIsArray($antiVirusHealth);

        return $antiVirusHealth;
    }
}
