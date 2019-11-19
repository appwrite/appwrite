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
    public function listProjects():array
    {
        $path   = str_replace([], [], '/projects');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Project
     *
     * @param string  $name
     * @param string  $teamId
     * @param string  $description
     * @param string  $logo
     * @param string  $url
     * @param string  $legalName
     * @param string  $legalCountry
     * @param string  $legalState
     * @param string  $legalCity
     * @param string  $legalAddress
     * @param string  $legalTaxId
     * @throws Exception
     * @return array
     */
    public function createProject(string $name, string $teamId, string $description = '', string $logo = '', string $url = '', string $legalName = '', string $legalCountry = '', string $legalState = '', string $legalCity = '', string $legalAddress = '', string $legalTaxId = ''):array
    {
        $path   = str_replace([], [], '/projects');
        $params = [];

        $params['name'] = $name;
        $params['teamId'] = $teamId;
        $params['description'] = $description;
        $params['logo'] = $logo;
        $params['url'] = $url;
        $params['legalName'] = $legalName;
        $params['legalCountry'] = $legalCountry;
        $params['legalState'] = $legalState;
        $params['legalCity'] = $legalCity;
        $params['legalAddress'] = $legalAddress;
        $params['legalTaxId'] = $legalTaxId;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Project
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function getProject(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Project
     *
     * @param string  $projectId
     * @param string  $name
     * @param string  $description
     * @param string  $logo
     * @param string  $url
     * @param string  $legalName
     * @param string  $legalCountry
     * @param string  $legalState
     * @param string  $legalCity
     * @param string  $legalAddress
     * @param string  $legalTaxId
     * @throws Exception
     * @return array
     */
    public function updateProject(string $projectId, string $name, string $description = '', string $logo = '', string $url = '', string $legalName = '', string $legalCountry = '', string $legalState = '', string $legalCity = '', string $legalAddress = '', string $legalTaxId = ''):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}');
        $params = [];

        $params['name'] = $name;
        $params['description'] = $description;
        $params['logo'] = $logo;
        $params['url'] = $url;
        $params['legalName'] = $legalName;
        $params['legalCountry'] = $legalCountry;
        $params['legalState'] = $legalState;
        $params['legalCity'] = $legalCity;
        $params['legalAddress'] = $legalAddress;
        $params['legalTaxId'] = $legalTaxId;

        return $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Project
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function deleteProject(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Keys
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function listKeys(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/keys');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Key
     *
     * @param string  $projectId
     * @param string  $name
     * @param array  $scopes
     * @throws Exception
     * @return array
     */
    public function createKey(string $projectId, string $name, array $scopes):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/keys');
        $params = [];

        $params['name'] = $name;
        $params['scopes'] = $scopes;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Key
     *
     * @param string  $projectId
     * @param string  $keyId
     * @throws Exception
     * @return array
     */
    public function getKey(string $projectId, string $keyId):array
    {
        $path   = str_replace(['{projectId}', '{keyId}'], [$projectId, $keyId], '/projects/{projectId}/keys/{keyId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Key
     *
     * @param string  $projectId
     * @param string  $keyId
     * @param string  $name
     * @param array  $scopes
     * @throws Exception
     * @return array
     */
    public function updateKey(string $projectId, string $keyId, string $name, array $scopes):array
    {
        $path   = str_replace(['{projectId}', '{keyId}'], [$projectId, $keyId], '/projects/{projectId}/keys/{keyId}');
        $params = [];

        $params['name'] = $name;
        $params['scopes'] = $scopes;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Key
     *
     * @param string  $projectId
     * @param string  $keyId
     * @throws Exception
     * @return array
     */
    public function deleteKey(string $projectId, string $keyId):array
    {
        $path   = str_replace(['{projectId}', '{keyId}'], [$projectId, $keyId], '/projects/{projectId}/keys/{keyId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Project OAuth
     *
     * @param string  $projectId
     * @param string  $provider
     * @param string  $appId
     * @param string  $secret
     * @throws Exception
     * @return array
     */
    public function updateProjectOAuth(string $projectId, string $provider, string $appId = '', string $secret = ''):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/oauth');
        $params = [];

        $params['provider'] = $provider;
        $params['appId'] = $appId;
        $params['secret'] = $secret;

        return $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Platforms
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function listPlatforms(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/platforms');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Platform
     *
     * @param string  $projectId
     * @param string  $type
     * @param string  $name
     * @param string  $key
     * @param string  $store
     * @param string  $url
     * @throws Exception
     * @return array
     */
    public function createPlatform(string $projectId, string $type, string $name, string $key = '', string $store = '', string $url = ''):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/platforms');
        $params = [];

        $params['type'] = $type;
        $params['name'] = $name;
        $params['key'] = $key;
        $params['store'] = $store;
        $params['url'] = $url;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Platform
     *
     * @param string  $projectId
     * @param string  $platformId
     * @throws Exception
     * @return array
     */
    public function getPlatform(string $projectId, string $platformId):array
    {
        $path   = str_replace(['{projectId}', '{platformId}'], [$projectId, $platformId], '/projects/{projectId}/platforms/{platformId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Platform
     *
     * @param string  $projectId
     * @param string  $platformId
     * @param string  $name
     * @param string  $key
     * @param string  $store
     * @param string  $url
     * @throws Exception
     * @return array
     */
    public function updatePlatform(string $projectId, string $platformId, string $name, string $key = '', string $store = '', string $url = ''):array
    {
        $path   = str_replace(['{projectId}', '{platformId}'], [$projectId, $platformId], '/projects/{projectId}/platforms/{platformId}');
        $params = [];

        $params['name'] = $name;
        $params['key'] = $key;
        $params['store'] = $store;
        $params['url'] = $url;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Platform
     *
     * @param string  $projectId
     * @param string  $platformId
     * @throws Exception
     * @return array
     */
    public function deletePlatform(string $projectId, string $platformId):array
    {
        $path   = str_replace(['{projectId}', '{platformId}'], [$projectId, $platformId], '/projects/{projectId}/platforms/{platformId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Tasks
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function listTasks(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/tasks');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Task
     *
     * @param string  $projectId
     * @param string  $name
     * @param string  $status
     * @param string  $schedule
     * @param int  $security
     * @param string  $httpMethod
     * @param string  $httpUrl
     * @param array  $httpHeaders
     * @param string  $httpUser
     * @param string  $httpPass
     * @throws Exception
     * @return array
     */
    public function createTask(string $projectId, string $name, string $status, string $schedule, int $security, string $httpMethod, string $httpUrl, array $httpHeaders = [], string $httpUser = '', string $httpPass = ''):array
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
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Task
     *
     * @param string  $projectId
     * @param string  $taskId
     * @throws Exception
     * @return array
     */
    public function getTask(string $projectId, string $taskId):array
    {
        $path   = str_replace(['{projectId}', '{taskId}'], [$projectId, $taskId], '/projects/{projectId}/tasks/{taskId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Task
     *
     * @param string  $projectId
     * @param string  $taskId
     * @param string  $name
     * @param string  $status
     * @param string  $schedule
     * @param int  $security
     * @param string  $httpMethod
     * @param string  $httpUrl
     * @param array  $httpHeaders
     * @param string  $httpUser
     * @param string  $httpPass
     * @throws Exception
     * @return array
     */
    public function updateTask(string $projectId, string $taskId, string $name, string $status, string $schedule, int $security, string $httpMethod, string $httpUrl, array $httpHeaders = [], string $httpUser = '', string $httpPass = ''):array
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
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Task
     *
     * @param string  $projectId
     * @param string  $taskId
     * @throws Exception
     * @return array
     */
    public function deleteTask(string $projectId, string $taskId):array
    {
        $path   = str_replace(['{projectId}', '{taskId}'], [$projectId, $taskId], '/projects/{projectId}/tasks/{taskId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Project
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function getProjectUsage(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/usage');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Webhooks
     *
     * @param string  $projectId
     * @throws Exception
     * @return array
     */
    public function listWebhooks(string $projectId):array
    {
        $path   = str_replace(['{projectId}'], [$projectId], '/projects/{projectId}/webhooks');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Webhook
     *
     * @param string  $projectId
     * @param string  $name
     * @param array  $events
     * @param string  $url
     * @param int  $security
     * @param string  $httpUser
     * @param string  $httpPass
     * @throws Exception
     * @return array
     */
    public function createWebhook(string $projectId, string $name, array $events, string $url, int $security, string $httpUser = '', string $httpPass = ''):array
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
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Webhook
     *
     * @param string  $projectId
     * @param string  $webhookId
     * @throws Exception
     * @return array
     */
    public function getWebhook(string $projectId, string $webhookId):array
    {
        $path   = str_replace(['{projectId}', '{webhookId}'], [$projectId, $webhookId], '/projects/{projectId}/webhooks/{webhookId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Webhook
     *
     * @param string  $projectId
     * @param string  $webhookId
     * @param string  $name
     * @param array  $events
     * @param string  $url
     * @param int  $security
     * @param string  $httpUser
     * @param string  $httpPass
     * @throws Exception
     * @return array
     */
    public function updateWebhook(string $projectId, string $webhookId, string $name, array $events, string $url, int $security, string $httpUser = '', string $httpPass = ''):array
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
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Webhook
     *
     * @param string  $projectId
     * @param string  $webhookId
     * @throws Exception
     * @return array
     */
    public function deleteWebhook(string $projectId, string $webhookId):array
    {
        $path   = str_replace(['{projectId}', '{webhookId}'], [$projectId, $webhookId], '/projects/{projectId}/webhooks/{webhookId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}