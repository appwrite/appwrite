const Service = require('../service.js');

class Auth extends Service {

    /**
     * Login User
     *
     * Allow the user to login into his account by providing a valid email and
     * password combination. Use the success and failure arguments to provide a
     * redirect URL\'s back to your app when login is completed. 
     * 
     * Please notice that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     * 
     * When not using the success or failure redirect arguments this endpoint will
     * result with a 200 status code and the user account object on success and
     * with 401 status error on failure. This behavior was applied to help the web
     * clients deal with browsers who don't allow to set 3rd party HTTP cookies
     * needed for saving the account session token.
     *
     * @param string email
     * @param string password
     * @param string success
     * @param string failure
     * @throws Exception
     * @return {}
     */
    async login(email, password, success = '', failure = '') {
        let path = '/auth/login';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'password': password,
                'success': success,
                'failure': failure
            });
    }

    /**
     * Logout Current Session
     *
     * Use this endpoint to log out the currently logged in user from his account.
     * When succeed this endpoint will delete the user session and remove the
     * session secret cookie.
     *
     * @throws Exception
     * @return {}
     */
    async logout() {
        let path = '/auth/logout';
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * Logout Specific Session
     *
     * Use this endpoint to log out the currently logged in user from all his
     * account sessions across all his different devices. When using the option id
     * argument, only the session unique ID provider will be deleted.
     *
     * @param string id
     * @throws Exception
     * @return {}
     */
    async logoutBySession(id) {
        let path = '/auth/logout/{id}'.replace(new RegExp('{id}', 'g'), id);
        
        return await this.client.call('delete', path, {'content-type': 'application/json'},
            {
            });
    }

    /**
     * OAuth Callback
     *
     * @param string projectId
     * @param string provider
     * @param string code
     * @param string state
     * @throws Exception
     * @return {}
     */
    async oauthCallback(projectId, provider, code, state = '') {
        let path = '/auth/oauth/callback/{provider}/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{provider}', 'g'), provider);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'code': code,
                'state': state
            });
    }

    /**
     * OAuth Login
     *
     * @param string provider
     * @param string success
     * @param string failure
     * @throws Exception
     * @return {}
     */
    async oauth(provider, success = '', failure = '') {
        let path = '/auth/oauth/{provider}'.replace(new RegExp('{provider}', 'g'), provider);
        
        return await this.client.call('get', path, {'content-type': 'application/json'},
            {
                'success': success,
                'failure': failure
            });
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
     * @param string email
     * @param string redirect
     * @throws Exception
     * @return {}
     */
    async recovery(email, redirect) {
        let path = '/auth/recovery';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'redirect': redirect
            });
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
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     *
     * @param string userId
     * @param string token
     * @param string passwordA
     * @param string passwordB
     * @throws Exception
     * @return {}
     */
    async recoveryReset(userId, token, passwordA, passwordB) {
        let path = '/auth/recovery/reset';
        
        return await this.client.call('put', path, {'content-type': 'application/json'},
            {
                'userId': userId,
                'token': token,
                'password-a': passwordA,
                'password-b': passwordB
            });
    }

    /**
     * Register User
     *
     * Use this endpoint to allow a new user to register an account in your
     * project. Use the success and failure URL's to redirect users back to your
     * application after signup completes.
     * 
     * If registration completes successfully user will be sent with a
     * confirmation email in order to confirm he is the owner of the account email
     * address. Use the redirect parameter to redirect the user from the
     * confirmation email back to your app. When the user is redirected, use the
     * /auth/confirm endpoint to complete the account confirmation.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     * 
     * When not using the success or failure redirect arguments this endpoint will
     * result with a 200 status code and the user account object on success and
     * with 401 status error on failure. This behavior was applied to help the web
     * clients deal with browsers who don't allow to set 3rd party HTTP cookies
     * needed for saving the account session token.
     *
     * @param string email
     * @param string password
     * @param string redirect
     * @param string name
     * @param string success
     * @param string failure
     * @throws Exception
     * @return {}
     */
    async register(email, password, redirect, name = '', success = '', failure = '') {
        let path = '/auth/register';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'email': email,
                'password': password,
                'name': name,
                'redirect': redirect,
                'success': success,
                'failure': failure
            });
    }

    /**
     * Confirm User
     *
     * Use this endpoint to complete the confirmation of the user account email
     * address. Both the **userId** and **token** arguments will be passed as
     * query parameters to the redirect URL you have provided when sending your
     * request to the /auth/register endpoint.
     *
     * @param string userId
     * @param string token
     * @throws Exception
     * @return {}
     */
    async confirm(userId, token) {
        let path = '/auth/register/confirm';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'userId': userId,
                'token': token
            });
    }

    /**
     * Resend Confirmation
     *
     * This endpoint allows the user to request your app to resend him his email
     * confirmation message. The redirect arguments acts the same way as in
     * /auth/register endpoint.
     * 
     * Please notice that in order to avoid a [Redirect
     * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URL's are the once from domains you have set when
     * added your platforms in the console interface.
     *
     * @param string redirect
     * @throws Exception
     * @return {}
     */
    async confirmResend(redirect) {
        let path = '/auth/register/confirm/resend';
        
        return await this.client.call('post', path, {'content-type': 'application/json'},
            {
                'redirect': redirect
            });
    }
}

module.exports = Auth;