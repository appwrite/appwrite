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
     * Allow the user to login into his account by providing a valid email and password combination. Use the success and failure arguments to provide a redirect URL\&#039;s back to your app when login is completed. 

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface.

When not using the success or failure redirect arguments this endpoint will result with a 200 status code and the user account object on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don&#039;t allow to set 3rd party HTTP cookies needed for saving the account session token.
     *
     * @param string $email
     * @param string $password
     * @param string $success
     * @param string $failure
     * @throws Exception
     * @return array
     */
    public function login($email, $password, $success = '', $failure = '')
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
     * Use this endpoint to log out the currently logged in user from his account. When succeed this endpoint will delete the user session and remove the session secret cookie.
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
     * Use this endpoint to log out the currently logged in user from all his account sessions across all his different devices. When using the option id argument, only the session unique ID provider will be deleted.
     *
     * @param string $userId
     * @throws Exception
     * @return array
     */
    public function logoutBySession($userId)
    {
        $path   = str_replace(['{userId}'], [$userId], '/auth/logout/{userId}');
        $params = [];


        return $this->client->call(Client::METHOD_DELETE, $path, [
        ], $params);
    }

    /**
     * Password Recovery
     *
     * Sends the user an email with a temporary secret token for password reset. When the user clicks the confirmation link he is redirected back to your app password reset redirect URL with a secret token and email address values attached to the URL query string. Use the query string params to submit a request to the /auth/password/reset endpoint to complete the process.
     *
     * @param string $email
     * @param string $redirect
     * @throws Exception
     * @return array
     */
    public function recovery($email, $redirect)
    {
        $path   = str_replace([], [], '/auth/recovery');
        $params = [];

        $params['email'] = $email;
        $params['redirect'] = $redirect;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Password Reset
     *
     * Use this endpoint to complete the user account password reset. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/recovery endpoint.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface.
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
     * Use this endpoint to allow a new user to register an account in your project. Use the success and failure URL&#039;s to redirect users back to your application after signup completes.

If registration completes successfully user will be sent with a confirmation email in order to confirm he is the owner of the account email address. Use the redirect parameter to redirect the user from the confirmation email back to your app. When the user is redirected, use the /auth/confirm endpoint to complete the account confirmation.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface.

When not using the success or failure redirect arguments this endpoint will result with a 200 status code and the user account object on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don&#039;t allow to set 3rd party HTTP cookies needed for saving the account session token.
     *
     * @param string $email
     * @param string $password
     * @param string $redirect
     * @param string $name
     * @param string $success
     * @param string $failure
     * @throws Exception
     * @return array
     */
    public function register($email, $password, $redirect, $name = '', $success = '', $failure = '')
    {
        $path   = str_replace([], [], '/auth/register');
        $params = [];

        $params['email'] = $email;
        $params['password'] = $password;
        $params['name'] = $name;
        $params['redirect'] = $redirect;
        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * Confirm User
     *
     * Use this endpoint to complete the confirmation of the user account email address. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/register endpoint.
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
     * This endpoint allows the user to request your app to resend him his email confirmation message. The redirect arguments acts the same way as in /auth/register endpoint.

Please notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL&#039;s are the once from domains you have set when added your platforms in the console interface.
     *
     * @param string $redirect
     * @throws Exception
     * @return array
     */
    public function confirmResend($redirect)
    {
        $path   = str_replace([], [], '/auth/register/confirm/resend');
        $params = [];

        $params['redirect'] = $redirect;

        return $this->client->call(Client::METHOD_POST, $path, [
        ], $params);
    }

    /**
     * OAuth Callback
     *
     * @param string $projectId
     * @param string $provider
     * @param string $code
     * @param string $state
     * @throws Exception
     * @return array
     */
    public function oauthCallback($projectId, $provider, $code, $state = '')
    {
        $path   = str_replace(['{projectId}', '{provider}'], [$projectId, $provider], '/oauth/callback/{provider}/{projectId}');
        $params = [];

        $params['code'] = $code;
        $params['state'] = $state;

        return $this->client->call(Client::METHOD_GET, $path, [
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
        $path   = str_replace(['{provider}'], [$provider], '/oauth/{provider}');
        $params = [];

        $params['success'] = $success;
        $params['failure'] = $failure;

        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

}