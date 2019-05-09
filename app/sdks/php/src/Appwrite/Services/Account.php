<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Account extends Service
{
    /**
     * Get Account
     *
     * Get currently logged in user data as JSON object.
     *
     * @throws Exception
     * @return array
     */
    public function get()
    {
        $path   = str_replace([], [], '/account');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Delete Account
     *
     * Delete currently logged in user account.
     *
     * @throws Exception
     * @return array
     */
    public function delete()
    {
        $path   = str_replace([], [], '/account');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Update Account Email
     *
     * Update currently logged in user account email address. After changing user address, user confirmation status is being reset and a new confirmation mail is sent. For security measures, user password is required to complete this request.
     *
     * @param string $email
     * @param string $password
     * @throws Exception
     * @return array
     */
    public function updateEmail($email, $password)
    {
        $path   = str_replace([], [], '/account/email');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Update Account Name
     *
     * Update currently logged in user account name.
     *
     * @param string $name
     * @throws Exception
     * @return array
     */
    public function updateName($name)
    {
        $path   = str_replace([], [], '/account/name');
        $params = [];

        $params['name'] = $name;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Update Account Password
     *
     * Update currently logged in user password. For validation, user is required to pass the password twice.
     *
     * @param string $password
     * @param string $oldPassword
     * @throws Exception
     * @return array
     */
    public function updatePassword($password, $oldPassword)
    {
        $path   = str_replace([], [], '/account/password');
        $params = [];

        $params['password'] = $password;
        $params['old-password'] = $oldPassword;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Get Account Preferences
     *
     * Get currently logged in user preferences key-value object.
     *
     * @throws Exception
     * @return array
     */
    public function getPrefs()
    {
        $path   = str_replace([], [], '/account/prefs');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Update Account Prefs
     *
     * Update currently logged in user account preferences. You can pass only the specific settings you wish to update.
     *
     * @param string $prefs
     * @throws Exception
     * @return array
     */
    public function updatePrefs($prefs)
    {
        $path   = str_replace([], [], '/account/prefs');
        $params = [];

        $params['prefs'] = $prefs;

        return $this->client->call(Client::METHOD_PATCH, $path, [
        ], $params);
    }

    /**
     * Get Account Security Log
     *
     * Get currently logged in user list of latest security activity logs. Each log returns user IP address, location and date and time of log.
     *
     * @throws Exception
     * @return array
     */
    public function getSecurity()
    {
        $path   = str_replace([], [], '/account/security');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * Get Account Active Sessions
     *
     * Get currently logged in user list of active sessions across different devices.
     *
     * @throws Exception
     * @return array
     */
    public function getSessions()
    {
        $path   = str_replace([], [], '/account/sessions');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

}