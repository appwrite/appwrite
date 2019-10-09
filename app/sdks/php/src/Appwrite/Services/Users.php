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
     * @param string $search
     * @param integer $limit
     * @param integer $offset
     * @param string $orderType
     * @throws Exception
     * @return array
     */
    public function listUsers($search = '', $limit = 25, $offset = 0, $orderType = 'ASC')
    {
        $path   = str_replace([], [], '/users');
        $params = [];

        $params['search'] = $search;
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $params['orderType'] = $orderType;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Create User
     *
     * Create a new user.
     *
     * @param string $email
     * @param string $password
     * @param string $name
     * @throws Exception
     * @return array
     */
    public function createUser($email, $password, $name = '')
    {
        $path   = str_replace([], [], '/users');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;
        $params['name'] = $name;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Get User
     *
     * Get user by its unique ID.
     *
     * @param string $userId
     * @throws Exception
     * @return array
     */
    public function getUser($userId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get User Logs
     *
     * Get user activity logs list by its unique ID.
     *
     * @param string $userId
     * @throws Exception
     * @return array
     */
    public function getUserLogs($userId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/logs');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get User Prefs
     *
     * Get user preferences by its unique ID.
     *
     * @param string $userId
     * @throws Exception
     * @return array
     */
    public function getUserPrefs($userId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/prefs');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Account Prefs
     *
     * Update user preferences by its unique ID. You can pass only the specific
     * settings you wish to update.
     *
     * @param string $userId
     * @param string $prefs
     * @throws Exception
     * @return array
     */
    public function updateUserPrefs($userId, $prefs)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/prefs');
        $params = [];

        $params['prefs'] = $prefs;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Get User Sessions
     *
     * Get user sessions list by its unique ID.
     *
     * @param string $userId
     * @throws Exception
     * @return array
     */
    public function getUserSessions($userId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/sessions');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Delete User Sessions
     *
     * Delete all user sessions by its unique ID.
     *
     * @param string $userId
     * @throws Exception
     * @return array
     */
    public function deleteUserSessions($userId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/sessions');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Delete User Session
     *
     * Delete user sessions by its unique ID.
     *
     * @param string $userId
     * @param string $sessionId
     * @throws Exception
     * @return array
     */
    public function deleteUserSession($userId, $sessionId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/sessions/:session');
        $params = [];

        $params['sessionId'] = $sessionId;

        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Update user status
     *
     * Update user status by its unique ID.
     *
     * @param string $userId
     * @param string $status
     * @throws Exception
     * @return array
     */
    public function updateUserStatus($userId, $status)
    {
        $path   = str_replace(['{userId}'], [$userId], '/users/{userId}/status');
        $params = [];

        $params['status'] = $status;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

}