<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Auth extends Service
{
    /**
     * Login User
     *
     * /docs/references/auth/login.md
     *
     * @param string $email
     * @param string $password
     * @param string $success
     * @param string $failure
     * @throws Exception
     * @return array
     */
    public function login($email, $password, $success, $failure)
    {
        $path   = str_replace([], [], '/auth/login');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;
        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Logout Current Session
     *
     * /docs/references/auth/logout.md
     *
     * @throws Exception
     * @return array
     */
    public function logout()
    {
        $path   = str_replace([], [], '/auth/logout');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Logout Specific Session
     *
     * /docs/references/auth/logout-by-session.md
     *
     * @param string $id
     * @throws Exception
     * @return array
     */
    public function logoutBySession($id)
    {
        $path   = str_replace(['{id}'], [$id], '/auth/logout/{id}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * OAuth Login
     *
     * @param string $provider
     * @param string $success
     * @param string $failure
     * @throws Exception
     * @return array
     */
    public function oauth($provider, $success = '', $failure = '')
    {
        $path   = str_replace(['{provider}'], [$provider], '/auth/oauth/{provider}');
        $params = [];

        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Password Recovery
     *
     * /docs/references/auth/recovery.md
     *
     * @param string $email
     * @param string $reset
     * @throws Exception
     * @return array
     */
    public function recovery($email, $reset)
    {
        $path   = str_replace([], [], '/auth/recovery');
        $params = [];

        $params['email'] = $email;
        $params['reset'] = $reset;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Password Reset
     *
     * /docs/references/auth/recovery-reset.md
     *
     * @param string $userId
     * @param string $token
     * @param string $passwordA
     * @param string $passwordB
     * @throws Exception
     * @return array
     */
    public function recoveryReset($userId, $token, $passwordA, $passwordB)
    {
        $path   = str_replace([], [], '/auth/recovery/reset');
        $params = [];

        $params['userId'] = $userId;
        $params['token'] = $token;
        $params['password-a'] = $passwordA;
        $params['password-b'] = $passwordB;

        return $this->client->call(Client::METHOD_PUT, $path, [
        ], $params);
    }

    /**
     * Register User
     *
     * /docs/references/auth/register.md
     *
     * @param string $email
     * @param string $password
     * @param string $confirm
     * @param string $success
     * @param string $failure
     * @param string $name
     * @throws Exception
     * @return array
     */
    public function register($email, $password, $confirm, $success = '', $failure = '', $name = '')
    {
        $path   = str_replace([], [], '/auth/register');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;
        $params['confirm'] = $confirm;
        $params['success'] = $success;
        $params['failure'] = $failure;
        $params['name'] = $name;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Confirm User
     *
     * /docs/references/auth/confirm.md
     *
     * @param string $userId
     * @param string $token
     * @throws Exception
     * @return array
     */
    public function confirm($userId, $token)
    {
        $path   = str_replace([], [], '/auth/register/confirm');
        $params = [];

        $params['userId'] = $userId;
        $params['token'] = $token;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Resend Confirmation
     *
     * /docs/references/auth/confirm-resend.md
     *
     * @param string $confirm
     * @throws Exception
     * @return array
     */
    public function confirmResend($confirm)
    {
        $path   = str_replace([], [], '/auth/register/confirm/resend');
        $params = [];

        $params['confirm'] = $confirm;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

}