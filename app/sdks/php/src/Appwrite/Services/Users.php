<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Users extends Service
{
    /**
     * List Users
     *
     * Get a list of all the project users. You can use the query params to filter
     * your results.
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
        $path   = str_replace([], [], '/users');
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
     * Create User
     *
     * Create a new user.
     *
     * @param string  $email
     * @param string  $password
     * @param string  $name
     * @throws Exception
     * @return array
     */
    public function create(string $email, string $password, string $name = ''):array
    {
        $path   = str_replace([], [], '/users');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;
        $params['name'] = $name;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get User
     *
     * Get user by its unique ID.
     *
     * @param string  $userId
     * @throws Exception
     * @return array
     */
    public function get(string $userId):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get User Logs
     *
     * Get user activity logs list by its unique ID.
     *
     * @param string  $userId
     * @throws Exception
     * @return array
     */
    public function getLogs(string $userId):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/logs');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get User Preferences
     *
     * Get user preferences by its unique ID.
     *
     * @param string  $userId
     * @throws Exception
     * @return array
     */
    public function getPrefs(string $userId):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/prefs');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update User Preferences
     *
     * Update user preferences by its unique ID. You can pass only the specific
     * settings you wish to update.
     *
     * @param string  $userId
     * @param array  $prefs
     * @throws Exception
     * @return array
     */
    public function updatePrefs(string $userId, array $prefs):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/prefs');
        $params = [];

        $params['prefs'] = $prefs;

        return $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Get User Sessions
     *
     * Get user sessions list by its unique ID.
     *
     * @param string  $userId
     * @throws Exception
     * @return array
     */
    public function getSessions(string $userId):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/sessions');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete User Sessions
     *
     * Delete all user sessions by its unique ID.
     *
     * @param string  $userId
     * @throws Exception
     * @return array
     */
    public function deleteSessions(string $userId):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/sessions');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Delete User Session
     *
     * Delete user sessions by its unique ID.
     *
     * @param string  $userId
     * @param string  $sessionId
     * @throws Exception
     * @return array
     */
    public function deleteSession(string $userId, string $sessionId):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/sessions/:session');
        $params = [];

        $params['sessionId'] = $sessionId;

        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Update User Status
     *
     * Update user status by its unique ID.
     *
     * @param string  $userId
     * @param string  $status
     * @throws Exception
     * @return array
     */
    public function updateStatus(string $userId, string $status):array
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/status');
        $params = [];

        $params['status'] = $status;

        return $this->client->call(Client::METHOD_PATCH, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}