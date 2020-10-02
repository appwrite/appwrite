class Account: Service
{
    /**
     * Get Account
     *
     * Get currently logged in user data as JSON object.
     *
     * @throws Exception
     * @return array
     */

    func get() -> Array<Any> {
        let path: String = "/account"


                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Create Account
     *
     * Use this endpoint to allow a new user to register a new account in your
     * project. After the user registration completes successfully, you can use
     * the [/account/verfication](/docs/client/account#createVerification) route
     * to start verifying the user email address. To allow your new user to login
     * to his new account, you need to create a new [account
     * session](/docs/client/account#createSession).
     *
     * @param String _email
     * @param String _password
     * @param String _name
     * @throws Exception
     * @return array
     */

    func create(_email: String, _password: String, _name: String = "") -> Array<Any> {
        let path: String = "/account"


                var params: [String: Any] = [:]
        
        params["email"] = _email
        params["password"] = _password
        params["name"] = _name

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Delete Account
     *
     * Delete a currently logged in user account. Behind the scene, the user
     * record is not deleted but permanently blocked from any access. This is done
     * to avoid deleted accounts being overtaken by new users with the same email
     * address. Any user-related resources like documents or storage files should
     * be deleted separately.
     *
     * @throws Exception
     * @return array
     */

    func delete() -> Array<Any> {
        let path: String = "/account"


                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Update Account Email
     *
     * Update currently logged in user account email address. After changing user
     * address, user confirmation status is being reset and a new confirmation
     * mail is sent. For security measures, user password is required to complete
     * this request.
     *
     * @param String _email
     * @param String _password
     * @throws Exception
     * @return array
     */

    func updateEmail(_email: String, _password: String) -> Array<Any> {
        let path: String = "/account/email"


                var params: [String: Any] = [:]
        
        params["email"] = _email
        params["password"] = _password

        return [self.client.call(method: Client.HTTPMethod.patch.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Get Account Logs
     *
     * Get currently logged in user list of latest security activity logs. Each
     * log returns user IP address, location and date and time of log.
     *
     * @throws Exception
     * @return array
     */

    func getLogs() -> Array<Any> {
        let path: String = "/account/logs"


                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Update Account Name
     *
     * Update currently logged in user account name.
     *
     * @param String _name
     * @throws Exception
     * @return array
     */

    func updateName(_name: String) -> Array<Any> {
        let path: String = "/account/name"


                var params: [String: Any] = [:]
        
        params["name"] = _name

        return [self.client.call(method: Client.HTTPMethod.patch.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Update Account Password
     *
     * Update currently logged in user password. For validation, user is required
     * to pass the password twice.
     *
     * @param String _password
     * @param String _oldPassword
     * @throws Exception
     * @return array
     */

    func updatePassword(_password: String, _oldPassword: String) -> Array<Any> {
        let path: String = "/account/password"


                var params: [String: Any] = [:]
        
        params["password"] = _password
        params["oldPassword"] = _oldPassword

        return [self.client.call(method: Client.HTTPMethod.patch.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Get Account Preferences
     *
     * Get currently logged in user preferences as a key-value object.
     *
     * @throws Exception
     * @return array
     */

    func getPrefs() -> Array<Any> {
        let path: String = "/account/prefs"


                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Update Account Preferences
     *
     * Update currently logged in user account preferences. You can pass only the
     * specific settings you wish to update.
     *
     * @param object _prefs
     * @throws Exception
     * @return array
     */

    func updatePrefs(_prefs: object) -> Array<Any> {
        let path: String = "/account/prefs"


                var params: [String: Any] = [:]
        
        params["prefs"] = _prefs

        return [self.client.call(method: Client.HTTPMethod.patch.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Create Password Recovery
     *
     * Sends the user an email with a temporary secret key for password reset.
     * When the user clicks the confirmation link he is redirected back to your
     * app password reset URL with the secret key and email address values
     * attached to the URL query string. Use the query string params to submit a
     * request to the [PUT /account/recovery](/docs/client/account#updateRecovery)
     * endpoint to complete the process.
     *
     * @param String _email
     * @param String _url
     * @throws Exception
     * @return array
     */

    func createRecovery(_email: String, _url: String) -> Array<Any> {
        let path: String = "/account/recovery"


                var params: [String: Any] = [:]
        
        params["email"] = _email
        params["url"] = _url

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Complete Password Recovery
     *
     * Use this endpoint to complete the user account password reset. Both the
     * **userId** and **secret** arguments will be passed as query parameters to
     * the redirect URL you have provided when sending your request to the [POST
     * /account/recovery](/docs/client/account#createRecovery) endpoint.
     * 
     * Please note that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     *
     * @param String _userId
     * @param String _secret
     * @param String _password
     * @param String _passwordAgain
     * @throws Exception
     * @return array
     */

    func updateRecovery(_userId: String, _secret: String, _password: String, _passwordAgain: String) -> Array<Any> {
        let path: String = "/account/recovery"


                var params: [String: Any] = [:]
        
        params["userId"] = _userId
        params["secret"] = _secret
        params["password"] = _password
        params["passwordAgain"] = _passwordAgain

        return [self.client.call(method: Client.HTTPMethod.put.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Get Account Sessions
     *
     * Get currently logged in user list of active sessions across different
     * devices.
     *
     * @throws Exception
     * @return array
     */

    func getSessions() -> Array<Any> {
        let path: String = "/account/sessions"


                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Create Account Session
     *
     * Allow the user to login into his account by providing a valid email and
     * password combination. This route will create a new session for the user.
     *
     * @param String _email
     * @param String _password
     * @throws Exception
     * @return array
     */

    func createSession(_email: String, _password: String) -> Array<Any> {
        let path: String = "/account/sessions"


                var params: [String: Any] = [:]
        
        params["email"] = _email
        params["password"] = _password

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Delete All Account Sessions
     *
     * Delete all sessions from the user account and remove any sessions cookies
     * from the end client.
     *
     * @throws Exception
     * @return array
     */

    func deleteSessions() -> Array<Any> {
        let path: String = "/account/sessions"


                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Create Account Session with OAuth2
     *
     * Allow the user to login to his account using the OAuth2 provider of his
     * choice. Each OAuth2 provider should be enabled from the Appwrite console
     * first. Use the success and failure arguments to provide a redirect URL's
     * back to your app when login is completed.
     *
     * @param String _provider
     * @param String _success
     * @param String _failure
     * @param Array<Any> _scopes
     * @throws Exception
     * @return array
     */

    func createOAuth2Session(_provider: String, _success: String = "https://appwrite.io/auth/oauth2/success", _failure: String = "https://appwrite.io/auth/oauth2/failure", _scopes: Array<Any> = []) -> Array<Any> {
        var path: String = "/account/sessions/oauth2/{provider}"

        path = path.replacingOccurrences(
          of: "{provider}",
          with: _provider
        )

                var params: [String: Any] = [:]
        
        params["success"] = _success
        params["failure"] = _failure
        params["scopes"] = _scopes

        return [self.client.call(method: Client.HTTPMethod.get.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Delete Account Session
     *
     * Use this endpoint to log out the currently logged in user from all his
     * account sessions across all his different devices. When using the option id
     * argument, only the session unique ID provider will be deleted.
     *
     * @param String _sessionId
     * @throws Exception
     * @return array
     */

    func deleteSession(_sessionId: String) -> Array<Any> {
        var path: String = "/account/sessions/{sessionId}"

        path = path.replacingOccurrences(
          of: "{sessionId}",
          with: _sessionId
        )

                let params: [String: Any] = [:]
        

        return [self.client.call(method: Client.HTTPMethod.delete.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Create Email Verification
     *
     * Use this endpoint to send a verification message to your user email address
     * to confirm they are the valid owners of that address. Both the **userId**
     * and **secret** arguments will be passed as query parameters to the URL you
     * have provided to be attached to the verification email. The provided URL
     * should redirect the user back to your app and allow you to complete the
     * verification process by verifying both the **userId** and **secret**
     * parameters. Learn more about how to [complete the verification
     * process](/docs/client/account#updateAccountVerification). 
     * 
     * Please note that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md),
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     * 
     *
     * @param String _url
     * @throws Exception
     * @return array
     */

    func createVerification(_url: String) -> Array<Any> {
        let path: String = "/account/verification"


                var params: [String: Any] = [:]
        
        params["url"] = _url

        return [self.client.call(method: Client.HTTPMethod.post.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

    /**
     * Complete Email Verification
     *
     * Use this endpoint to complete the user email verification process. Use both
     * the **userId** and **secret** parameters that were attached to your app URL
     * to verify the user email ownership. If confirmed this route will return a
     * 200 status code.
     *
     * @param String _userId
     * @param String _secret
     * @throws Exception
     * @return array
     */

    func updateVerification(_userId: String, _secret: String) -> Array<Any> {
        let path: String = "/account/verification"


                var params: [String: Any] = [:]
        
        params["userId"] = _userId
        params["secret"] = _secret

        return [self.client.call(method: Client.HTTPMethod.put.rawValue, path: path, headers: [
            "content-type": "application/json",
        ], params: params)]
    }

}
