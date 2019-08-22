<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Projects extends Service
{
    /**
     * List Projects
     *
     * @throws Exception
     * @return array
     */
    public function listProjects()
    {
        $path   = str_replace([], [], '/projects');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Project
     *
     * @param string $name
     * @param string $teamId
     * @param string $description
     * @param string $logo
     * @param string $url
     * @param array $clients
     * @param string $legalName
     * @param string $legalCountry
     * @param string $legalState
     * @param string $legalCity
     * @param string $legalAddress
     * @param string $legalTaxId
     * @throws Exception
     * @return array
     */
    public function createProject($name, $teamId, $description = '', $logo = '', $url = '', $clients = [], $legalName = '', $legalCountry = '', $legalState = '', $legalCity = '', $legalAddress = '', $legalTaxId = '')
    {
        $path   = str_replace([], [], '/projects');
        $params = [];

        $params['name'] = $name;
        $params['teamId'] = $teamId;
        $params['description'] = $description;
        $params['logo'] = $logo;
        $params['url'] = $url;
        $params['clients'] = $clients;
        $params['legalName'] = $legalName;
        $params['legalCountry'] = $legalCountry;
        $params['legalState'] = $legalState;
        $params['legalCity'] = $legalCity;
        $params['legalAddress'] = $legalAddress;
        $params['legalTaxId'] = $legalTaxId;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Project
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function getProject($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Project
     *
     * @param string $projectId
     * @param string $name
     * @param string $description
     * @param string $logo
     * @param string $url
     * @param array $clients
     * @param string $legalName
     * @param string $legalCountry
     * @param string $legalState
     * @param string $legalCity
     * @param string $legalAddress
     * @param string $legalTaxId
     * @throws Exception
     * @return array
     */
    public function updateProject($projectId, $name, $description = '', $logo = '', $url = '', $clients = [], $legalName = '', $legalCountry = '', $legalState = '', $legalCity = '', $legalAddress = '', $legalTaxId = '')
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}');
        $params = [];

        $params['name'] = $name;
        $params['description'] = $description;
        $params['logo'] = $logo;
        $params['url'] = $url;
        $params['clients'] = $clients;
        $params['legalName'] = $legalName;
        $params['legalCountry'] = $legalCountry;
        $params['legalState'] = $legalState;
        $params['legalCity'] = $legalCity;
        $params['legalAddress'] = $legalAddress;
        $params['legalTaxId'] = $legalTaxId;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Delete Project
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function deleteProject($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * List Keys
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function listKeys($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/keys');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Key
     *
     * @param string $projectId
     * @param string $name
     * @param array $scopes
     * @throws Exception
     * @return array
     */
    public function createKey($projectId, $name, $scopes)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/keys');
        $params = [];

        $params['name'] = $name;
        $params['scopes'] = $scopes;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Key
     *
     * @param string $projectId
     * @param string $keyId
     * @throws Exception
     * @return array
     */
    public function getKey($projectId, $keyId)
    {
        $path   = str_replace(['{projectId}', '{keyId}'], [$projectId, $keyId], '/projects/{projectId}/keys/{keyId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Key
     *
     * @param string $projectId
     * @param string $keyId
     * @param string $name
     * @param array $scopes
     * @throws Exception
     * @return array
     */
    public function updateKey($projectId, $keyId, $name, $scopes)
    {
        $path   = str_replace(['{projectId}', '{keyId}'], [$projectId, $keyId], '/projects/{projectId}/keys/{keyId}');
        $params = [];

        $params['name'] = $name;
        $params['scopes'] = $scopes;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete Key
     *
     * @param string $projectId
     * @param string $keyId
     * @throws Exception
     * @return array
     */
    public function deleteKey($projectId, $keyId)
    {
        $path   = str_replace(['{projectId}', '{keyId}'], [$projectId, $keyId], '/projects/{projectId}/keys/{keyId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Update Project OAuth
     *
     * @param string $projectId
     * @param string $provider
     * @param string $appId
     * @param string $secret
     * @throws Exception
     * @return array
     */
    public function updateProjectOAuth($projectId, $provider, $appId = '', $secret = '')
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/oauth');
        $params = [];

        $params['provider'] = $provider;
        $params['appId'] = $appId;
        $params['secret'] = $secret;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * List Platforms
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function listPlatforms($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/platforms');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Platform
     *
     * @param string $projectId
     * @param string $type
     * @param string $name
     * @param string $key
     * @param string $store
     * @param string $url
     * @throws Exception
     * @return array
     */
    public function createPlatform($projectId, $type, $name, $key = '', $store = '', $url = '')
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/platforms');
        $params = [];

        $params['type'] = $type;
        $params['name'] = $name;
        $params['key'] = $key;
        $params['store'] = $store;
        $params['url'] = $url;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Platform
     *
     * @param string $projectId
     * @param string $platformId
     * @throws Exception
     * @return array
     */
    public function getPlatform($projectId, $platformId)
    {
        $path   = str_replace(['{projectId}', '{platformId}'], [$projectId, $platformId], '/projects/{projectId}/platforms/{platformId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Platform
     *
     * @param string $projectId
     * @param string $platformId
     * @param string $name
     * @param string $key
     * @param string $store
     * @param string $url
     * @throws Exception
     * @return array
     */
    public function updatePlatform($projectId, $platformId, $name, $key = '', $store = '', $url = '[]')
    {
        $path   = str_replace(['{projectId}', '{platformId}'], [$projectId, $platformId], '/projects/{projectId}/platforms/{platformId}');
        $params = [];

        $params['name'] = $name;
        $params['key'] = $key;
        $params['store'] = $store;
        $params['url'] = $url;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete Platform
     *
     * @param string $projectId
     * @param string $platformId
     * @throws Exception
     * @return array
     */
    public function deletePlatform($projectId, $platformId)
    {
        $path   = str_replace(['{projectId}', '{platformId}'], [$projectId, $platformId], '/projects/{projectId}/platforms/{platformId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * List Tasks
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function listTasks($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/tasks');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Task
     *
     * @param string $projectId
     * @param string $name
     * @param string $status
     * @param string $schedule
     * @param integer $security
     * @param string $httpMethod
     * @param string $httpUrl
     * @param array $httpHeaders
     * @param string $httpUser
     * @param string $httpPass
     * @throws Exception
     * @return array
     */
    public function createTask($projectId, $name, $status, $schedule, $security, $httpMethod, $httpUrl, $httpHeaders = [], $httpUser = '', $httpPass = '')
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/tasks');
        $params = [];

        $params['name'] = $name;
        $params['status'] = $status;
        $params['schedule'] = $schedule;
        $params['security'] = $security;
        $params['httpMethod'] = $httpMethod;
        $params['httpUrl'] = $httpUrl;
        $params['httpHeaders'] = $httpHeaders;
        $params['httpUser'] = $httpUser;
        $params['httpPass'] = $httpPass;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Task
     *
     * @param string $projectId
     * @param string $taskId
     * @throws Exception
     * @return array
     */
    public function getTask($projectId, $taskId)
    {
        $path   = str_replace(['{projectId}', '{taskId}'], [$projectId, $taskId], '/projects/{projectId}/tasks/{taskId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Task
     *
     * @param string $projectId
     * @param string $taskId
     * @param string $name
     * @param string $status
     * @param string $schedule
     * @param integer $security
     * @param string $httpMethod
     * @param string $httpUrl
     * @param array $httpHeaders
     * @param string $httpUser
     * @param string $httpPass
     * @throws Exception
     * @return array
     */
    public function updateTask($projectId, $taskId, $name, $status, $schedule, $security, $httpMethod, $httpUrl, $httpHeaders = [], $httpUser = '', $httpPass = '')
    {
        $path   = str_replace(['{projectId}', '{taskId}'], [$projectId, $taskId], '/projects/{projectId}/tasks/{taskId}');
        $params = [];

        $params['name'] = $name;
        $params['status'] = $status;
        $params['schedule'] = $schedule;
        $params['security'] = $security;
        $params['httpMethod'] = $httpMethod;
        $params['httpUrl'] = $httpUrl;
        $params['httpHeaders'] = $httpHeaders;
        $params['httpUser'] = $httpUser;
        $params['httpPass'] = $httpPass;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete Task
     *
     * @param string $projectId
     * @param string $taskId
     * @throws Exception
     * @return array
     */
    public function deleteTask($projectId, $taskId)
    {
        $path   = str_replace(['{projectId}', '{taskId}'], [$projectId, $taskId], '/projects/{projectId}/tasks/{taskId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Get Project
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function getProjectUsage($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/usage');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * List Webhooks
     *
     * @param string $projectId
     * @throws Exception
     * @return array
     */
    public function listWebhooks($projectId)
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/webhooks');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create Webhook
     *
     * @param string $projectId
     * @param string $name
     * @param array $events
     * @param string $url
     * @param integer $security
     * @param string $httpUser
     * @param string $httpPass
     * @throws Exception
     * @return array
     */
    public function createWebhook($projectId, $name, $events, $url, $security, $httpUser = '', $httpPass = '')
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/webhooks');
        $params = [];

        $params['name'] = $name;
        $params['events'] = $events;
        $params['url'] = $url;
        $params['security'] = $security;
        $params['httpUser'] = $httpUser;
        $params['httpPass'] = $httpPass;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get Webhook
     *
     * @param string $projectId
     * @param string $webhookId
     * @throws Exception
     * @return array
     */
    public function getWebhook($projectId, $webhookId)
    {
        $path   = str_replace(['{projectId}', '{webhookId}'], [$projectId, $webhookId], '/projects/{projectId}/webhooks/{webhookId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Webhook
     *
     * @param string $projectId
     * @param string $webhookId
     * @param string $name
     * @param array $events
     * @param string $url
     * @param integer $security
     * @param string $httpUser
     * @param string $httpPass
     * @throws Exception
     * @return array
     */
    public function updateWebhook($projectId, $webhookId, $name, $events, $url, $security, $httpUser = '', $httpPass = '')
    {
        $path   = str_replace(['{projectId}', '{webhookId}'], [$projectId, $webhookId], '/projects/{projectId}/webhooks/{webhookId}');
        $params = [];

        $params['name'] = $name;
        $params['events'] = $events;
        $params['url'] = $url;
        $params['security'] = $security;
        $params['httpUser'] = $httpUser;
        $params['httpPass'] = $httpPass;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Delete Webhook
     *
     * @param string $projectId
     * @param string $webhookId
     * @throws Exception
     * @return array
     */
    public function deleteWebhook($projectId, $webhookId)
    {
        $path   = str_replace(['{projectId}', '{webhookId}'], [$projectId, $webhookId], '/projects/{projectId}/webhooks/{webhookId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

}