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
     * Allow the user to login into his account by providing a valid email and
     * password combination. Use the success and failure arguments to provide a
     * redirect URL\'s back to your app when login is completed. 
     * 
     * Please notice that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     * 
     * When accessing this route using Javascript from the browser, success and
     * failure parameter URLs are required. Appwrite server will respond with a
     * 301 redirect status code and will set the user session cookie. This
     * behavior is enforced because modern browsers are limiting 3rd party cookies
     * in XHR of fetch requests to protect user privacy.
     *
     * @param string  $email
     * @param string  $password
     * @param string  $success
     * @param string  $failure
     * @throws Exception
     * @return array
     */
    public function login(string $email, string $password, string $success = '', string $failure = ''):array
    {
        $path   = str_replace([], [], '/auth/login');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;
        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Logout Current Session
     *
     * Use this endpoint to log out the currently logged in user from his account.
     * When successful this endpoint will delete the user session and remove the
     * session secret cookie from the user client.
     *
     * @throws Exception
     * @return array
     */
    public function logout():array
    {
        $path   = str_replace([], [], '/auth/logout');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Logout Specific Session
     *
     * Use this endpoint to log out the currently logged in user from all his
     * account sessions across all his different devices. When using the option id
     * argument, only the session unique ID provider will be deleted.
     *
     * @param string  $id
     * @throws Exception
     * @return array
     */
    public function logoutBySession(string $id):array
    {
        $path   = str_replace(['{id}'], [$id], '/auth/logout/{id}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * OAuth Login
     *
     * @param string  $provider
     * @param string  $success
     * @param string  $failure
     * @throws Exception
     * @return array
     */
    public function oauth(string $provider, string $success, string $failure):array
    {
        $path   = str_replace(['{provider}'], [$provider], '/auth/oauth/{provider}');
        $params = [];

        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_GET, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Password Recovery
     *
     * Sends the user an email with a temporary secret token for password reset.
     * When the user clicks the confirmation link he is redirected back to your
     * app password reset redirect URL with a secret token and email address
     * values attached to the URL query string. Use the query string params to
     * submit a request to the /auth/password/reset endpoint to complete the
     * process.
     *
     * @param string  $email
     * @param string  $reset
     * @throws Exception
     * @return array
     */
    public function recovery(string $email, string $reset):array
    {
        $path   = str_replace([], [], '/auth/recovery');
        $params = [];

        $params['email'] = $email;
        $params['reset'] = $reset;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Password Reset
     *
     * Use this endpoint to complete the user account password reset. Both the
     * **userId** and **token** arguments will be passed as query parameters to
     * the redirect URL you have provided when sending your request to the
     * /auth/recovery endpoint.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     *
     * @param string  $userId
     * @param string  $token
     * @param string  $passwordA
     * @param string  $passwordB
     * @throws Exception
     * @return array
     */
    public function recoveryReset(string $userId, string $token, string $passwordA, string $passwordB):array
    {
        $path   = str_replace([], [], '/auth/recovery/reset');
        $params = [];

        $params['userId'] = $userId;
        $params['token'] = $token;
        $params['password-a'] = $passwordA;
        $params['password-b'] = $passwordB;

        return $this->client->call(Client::METHOD_PUT, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Register User
     *
     * Use this endpoint to allow a new user to register an account in your
     * project. Use the success and failure URLs to redirect users back to your
     * application after signup completes.
     * 
     * If registration completes successfully user will be sent with a
     * confirmation email in order to confirm he is the owner of the account email
     * address. Use the confirmation parameter to redirect the user from the
     * confirmation email back to your app. When the user is redirected, use the
     * /auth/confirm endpoint to complete the account confirmation.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     * 
     * When accessing this route using Javascript from the browser, success and
     * failure parameter URLs are required. Appwrite server will respond with a
     * 301 redirect status code and will set the user session cookie. This
     * behavior is enforced because modern browsers are limiting 3rd party cookies
     * in XHR of fetch requests to protect user privacy.
     *
     * @param string  $email
     * @param string  $password
     * @param string  $confirm
     * @param string  $success
     * @param string  $failure
     * @param string  $name
     * @throws Exception
     * @return array
     */
    public function register(string $email, string $password, string $confirm, string $success = '', string $failure = '', string $name = ''):array
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
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Confirm User
     *
     * Use this endpoint to complete the confirmation of the user account email
     * address. Both the **userId** and **token** arguments will be passed as
     * query parameters to the redirect URL you have provided when sending your
     * request to the /auth/register endpoint.
     *
     * @param string  $userId
     * @param string  $token
     * @throws Exception
     * @return array
     */
    public function confirm(string $userId, string $token):array
    {
        $path   = str_replace([], [], '/auth/register/confirm');
        $params = [];

        $params['userId'] = $userId;
        $params['token'] = $token;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

    /**
     * Resend Confirmation
     *
     * This endpoint allows the user to request your app to resend him his email
     * confirmation message. The redirect arguments act the same way as in
     * /auth/register endpoint.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     *
     * @param string  $confirm
     * @throws Exception
     * @return array
     */
    public function confirmResend(string $confirm):array
    {
        $path   = str_replace([], [], '/auth/register/confirm/resend');
        $params = [];

        $params['confirm'] = $confirm;

        return $this->client->call(Client::METHOD_POST, $path, [
            'content-type' => 'application/json',
        ], $params);
    }

}