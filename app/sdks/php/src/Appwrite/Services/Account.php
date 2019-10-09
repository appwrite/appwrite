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
     * /docs/references/account/get.md
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
     * /docs/references/account/delete.md
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
     * /docs/references/account/update-email.md
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
     * /docs/references/account/update-name.md
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
     * /docs/references/account/update-password.md
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
     * /docs/references/account/get-prefs.md
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
     * /docs/references/account/update-prefs.md
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
     * /docs/references/account/get-security.md
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
     * /docs/references/account/get-sessions.md
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