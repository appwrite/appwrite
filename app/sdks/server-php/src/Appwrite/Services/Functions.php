<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Functions extends Service
{
    /**
     * List Functions
     *
     * @param string  $search
     * @param int  $limit
     * @param int  $offset
     * @param string  $orderType
     * @throws Exception
     * @return array
     */
    public function list(string $search = '', int $limit = 25, int $offset = 0, string $orderType = 'ASC'):array
    {
        $path   = str_replace([], [], '/functions');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Function
     *
     * @param string  $name
     * @param array  $vars
     * @param string  $trigger
     * @param array  $events
     * @param string  $schedule
     * @param int  $timeout
     * @throws Exception
     * @return array
     */
    public function create(string $name, array $vars, string $trigger, array $events, string $schedule, int $timeout):array
    {
        $path   = str_replace([], [], '/functions');
        $params = [];

        $params['name'] = $name;
        $params['vars'] = $vars;
        $params['trigger'] = $trigger;
        $params['events'] = $events;
        $params['schedule'] = $schedule;
        $params['timeout'] = $timeout;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Function
     *
     * @param string  $functionId
     * @throws Exception
     * @return array
     */
    public function get(string $functionId):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Function
     *
     * @param string  $functionId
     * @param string  $name
     * @param array  $vars
     * @param string  $trigger
     * @param array  $events
     * @param string  $schedule
     * @param int  $timeout
     * @throws Exception
     * @return array
     */
    public function update(string $functionId, string $name, array $vars, string $trigger, array $events, string $schedule, int $timeout):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}');
        $params = [];

        $params['name'] = $name;
        $params['vars'] = $vars;
        $params['trigger'] = $trigger;
        $params['events'] = $events;
        $params['schedule'] = $schedule;
        $params['timeout'] = $timeout;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Function
     *
     * @param string  $functionId
     * @throws Exception
     * @return array
     */
    public function delete(string $functionId):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update Function Active Tag
     *
     * @param string  $functionId
     * @param string  $active
     * @throws Exception
     * @return array
     */
    public function updateActive(string $functionId, string $active):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}/active');
        $params = [];

        $params['active'] = $active;

        return $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Executions
     *
     * @param string  $functionId
     * @param string  $search
     * @param int  $limit
     * @param int  $offset
     * @param string  $orderType
     * @throws Exception
     * @return array
     */
    public function listExecutions(string $functionId, string $search = '', int $limit = 25, int $offset = 0, string $orderType = 'ASC'):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}/executions');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Execution
     *
     * @param string  $functionId
     * @param int  $async
     * @throws Exception
     * @return array
     */
    public function createExecution(string $functionId, int $async = 1):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}/executions');
        $params = [];

        $params['async'] = $async;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Execution
     *
     * @param string  $functionId
     * @param string  $executionId
     * @throws Exception
     * @return array
     */
    public function getExecution(string $functionId, string $executionId):array
    {
        $path   = str_replace(['{functionId}', '{executionId}'], [$functionId, $executionId], '/functions/{functionId}/executions/{executionId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * List Tags
     *
     * @param string  $functionId
     * @param string  $search
     * @param int  $limit
     * @param int  $offset
     * @param string  $orderType
     * @throws Exception
     * @return array
     */
    public function listTags(string $functionId, string $search = '', int $limit = 25, int $offset = 0, string $orderType = 'ASC'):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}/tags');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Create Tag
     *
     * @param string  $functionId
     * @param string  $env
     * @param string  $command
     * @param string  $code
     * @throws Exception
     * @return array
     */
    public function createTag(string $functionId, string $env, string $command, string $code):array
    {
        $path   = str_replace(['{functionId}'], [$functionId], '/functions/{functionId}/tags');
        $params = [];

        $params['env'] = $env;
        $params['command'] = $command;
        $params['code'] = $code;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get Tag
     *
     * @param string  $functionId
     * @param string  $tagId
     * @throws Exception
     * @return array
     */
    public function getTag(string $functionId, string $tagId):array
    {
        $path   = str_replace(['{functionId}', '{tagId}'], [$functionId, $tagId], '/functions/{functionId}/tags/{tagId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete Tag
     *
     * @param string  $functionId
     * @param string  $tagId
     * @throws Exception
     * @return array
     */
    public function deleteTag(string $functionId, string $tagId):array
    {
        $path   = str_replace(['{functionId}', '{tagId}'], [$functionId, $tagId], '/functions/{functionId}/tags/{tagId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}