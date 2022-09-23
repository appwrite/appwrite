import { Service } from '../service';
import { AppwriteException, Client } from '../client';
import type { Models } from '../models';
import type { UploadProgress, Payload } from '../client';

export class Account extends Service {

     constructor(client: Client)
     {
        super(client);
     }

        /**
         * Get Account
         *
         * Get currently logged in user data as JSON object.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async get<Preferences extends Models.Preferences>(): Promise<Models.Account<Preferences>> {
            let path = '/account';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Account
         *
         * Use this endpoint to allow a new user to register a new account in your
         * project. After the user registration completes successfully, you can use
         * the [/account/verfication](/docs/client/account#accountCreateVerification)
         * route to start verifying the user email address. To allow the new user to
         * login to their new account, you need to create a new [account
         * session](/docs/client/account#accountCreateSession).
         *
         * @param {string} userId
         * @param {string} email
         * @param {string} password
         * @param {string} name
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async create<Preferences extends Models.Preferences>(userId: string, email: string, password: string, name?: string): Promise<Models.Account<Preferences>> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof email === 'undefined') {
                throw new AppwriteException('Missing required parameter: "email"');
            }

            if (typeof password === 'undefined') {
                throw new AppwriteException('Missing required parameter: "password"');
            }

            let path = '/account';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof email !== 'undefined') {
                payload['email'] = email;
            }

            if (typeof password !== 'undefined') {
                payload['password'] = password;
            }

            if (typeof name !== 'undefined') {
                payload['name'] = name;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Account Email
         *
         * Update currently logged in user account email address. After changing user
         * address, the user confirmation status will get reset. A new confirmation
         * email is not sent automatically however you can use the send confirmation
         * email endpoint again to send the confirmation email. For security measures,
         * user password is required to complete this request.
         * This endpoint can also be used to convert an anonymous account to a normal
         * one, by passing an email address and a new password.
         * 
         *
         * @param {string} email
         * @param {string} password
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateEmail<Preferences extends Models.Preferences>(email: string, password: string): Promise<Models.Account<Preferences>> {
            if (typeof email === 'undefined') {
                throw new AppwriteException('Missing required parameter: "email"');
            }

            if (typeof password === 'undefined') {
                throw new AppwriteException('Missing required parameter: "password"');
            }

            let path = '/account/email';
            let payload: Payload = {};

            if (typeof email !== 'undefined') {
                payload['email'] = email;
            }

            if (typeof password !== 'undefined') {
                payload['password'] = password;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Account JWT
         *
         * Use this endpoint to create a JSON Web Token. You can use the resulting JWT
         * to authenticate on behalf of the current user when working with the
         * Appwrite server-side API and SDKs. The JWT secret is valid for 15 minutes
         * from its creation and will be invalid if the user will logout in that time
         * frame.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createJWT(): Promise<Models.Jwt> {
            let path = '/account/jwt';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * List Account Logs
         *
         * Get currently logged in user list of latest security activity logs. Each
         * log returns user IP address, location and date and time of log.
         *
         * @param {string[]} queries
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async listLogs(queries?: string[]): Promise<Models.LogList> {
            let path = '/account/logs';
            let payload: Payload = {};

            if (typeof queries !== 'undefined') {
                payload['queries'] = queries;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Account Name
         *
         * Update currently logged in user account name.
         *
         * @param {string} name
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateName<Preferences extends Models.Preferences>(name: string): Promise<Models.Account<Preferences>> {
            if (typeof name === 'undefined') {
                throw new AppwriteException('Missing required parameter: "name"');
            }

            let path = '/account/name';
            let payload: Payload = {};

            if (typeof name !== 'undefined') {
                payload['name'] = name;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Account Password
         *
         * Update currently logged in user password. For validation, user is required
         * to pass in the new password, and the old password. For users created with
         * OAuth, Team Invites and Magic URL, oldPassword is optional.
         *
         * @param {string} password
         * @param {string} oldPassword
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updatePassword<Preferences extends Models.Preferences>(password: string, oldPassword?: string): Promise<Models.Account<Preferences>> {
            if (typeof password === 'undefined') {
                throw new AppwriteException('Missing required parameter: "password"');
            }

            let path = '/account/password';
            let payload: Payload = {};

            if (typeof password !== 'undefined') {
                payload['password'] = password;
            }

            if (typeof oldPassword !== 'undefined') {
                payload['oldPassword'] = oldPassword;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Account Phone
         *
         * Update the currently logged in user's phone number. After updating the
         * phone number, the phone verification status will be reset. A confirmation
         * SMS is not sent automatically, however you can use the [POST
         * /account/verification/phone](/docs/client/account#accountCreatePhoneVerification)
         * endpoint to send a confirmation SMS.
         *
         * @param {string} phone
         * @param {string} password
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updatePhone<Preferences extends Models.Preferences>(phone: string, password: string): Promise<Models.Account<Preferences>> {
            if (typeof phone === 'undefined') {
                throw new AppwriteException('Missing required parameter: "phone"');
            }

            if (typeof password === 'undefined') {
                throw new AppwriteException('Missing required parameter: "password"');
            }

            let path = '/account/phone';
            let payload: Payload = {};

            if (typeof phone !== 'undefined') {
                payload['phone'] = phone;
            }

            if (typeof password !== 'undefined') {
                payload['password'] = password;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Get Account Preferences
         *
         * Get currently logged in user preferences as a key-value object.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async getPrefs<Preferences extends Models.Preferences>(): Promise<Preferences> {
            let path = '/account/prefs';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Account Preferences
         *
         * Update currently logged in user account preferences. The object you pass is
         * stored as is, and replaces any previous value. The maximum allowed prefs
         * size is 64kB and throws error if exceeded.
         *
         * @param {object} prefs
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updatePrefs<Preferences extends Models.Preferences>(prefs: object): Promise<Models.Account<Preferences>> {
            if (typeof prefs === 'undefined') {
                throw new AppwriteException('Missing required parameter: "prefs"');
            }

            let path = '/account/prefs';
            let payload: Payload = {};

            if (typeof prefs !== 'undefined') {
                payload['prefs'] = prefs;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Password Recovery
         *
         * Sends the user an email with a temporary secret key for password reset.
         * When the user clicks the confirmation link he is redirected back to your
         * app password reset URL with the secret key and email address values
         * attached to the URL query string. Use the query string params to submit a
         * request to the [PUT
         * /account/recovery](/docs/client/account#accountUpdateRecovery) endpoint to
         * complete the process. The verification link sent to the user's email
         * address is valid for 1 hour.
         *
         * @param {string} email
         * @param {string} url
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createRecovery(email: string, url: string): Promise<Models.Token> {
            if (typeof email === 'undefined') {
                throw new AppwriteException('Missing required parameter: "email"');
            }

            if (typeof url === 'undefined') {
                throw new AppwriteException('Missing required parameter: "url"');
            }

            let path = '/account/recovery';
            let payload: Payload = {};

            if (typeof email !== 'undefined') {
                payload['email'] = email;
            }

            if (typeof url !== 'undefined') {
                payload['url'] = url;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Password Recovery (confirmation)
         *
         * Use this endpoint to complete the user account password reset. Both the
         * **userId** and **secret** arguments will be passed as query parameters to
         * the redirect URL you have provided when sending your request to the [POST
         * /account/recovery](/docs/client/account#accountCreateRecovery) endpoint.
         * 
         * Please note that in order to avoid a [Redirect
         * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
         * the only valid redirect URLs are the ones from domains you have set when
         * adding your platforms in the console interface.
         *
         * @param {string} userId
         * @param {string} secret
         * @param {string} password
         * @param {string} passwordAgain
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateRecovery(userId: string, secret: string, password: string, passwordAgain: string): Promise<Models.Token> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof secret === 'undefined') {
                throw new AppwriteException('Missing required parameter: "secret"');
            }

            if (typeof password === 'undefined') {
                throw new AppwriteException('Missing required parameter: "password"');
            }

            if (typeof passwordAgain === 'undefined') {
                throw new AppwriteException('Missing required parameter: "passwordAgain"');
            }

            let path = '/account/recovery';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof secret !== 'undefined') {
                payload['secret'] = secret;
            }

            if (typeof password !== 'undefined') {
                payload['password'] = password;
            }

            if (typeof passwordAgain !== 'undefined') {
                payload['passwordAgain'] = passwordAgain;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('put', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * List Account Sessions
         *
         * Get currently logged in user list of active sessions across different
         * devices.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async listSessions(): Promise<Models.SessionList> {
            let path = '/account/sessions';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Delete All Account Sessions
         *
         * Delete all sessions from the user account and remove any sessions cookies
         * from the end client.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async deleteSessions(): Promise<{}> {
            let path = '/account/sessions';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('delete', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Anonymous Session
         *
         * Use this endpoint to allow a new user to register an anonymous account in
         * your project. This route will also create a new session for the user. To
         * allow the new user to convert an anonymous account to a normal account, you
         * need to update its [email and
         * password](/docs/client/account#accountUpdateEmail) or create an [OAuth2
         * session](/docs/client/account#accountCreateOAuth2Session).
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createAnonymousSession(): Promise<Models.Session> {
            let path = '/account/sessions/anonymous';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Account Session with Email
         *
         * Allow the user to login into their account by providing a valid email and
         * password combination. This route will create a new session for the user.
         *
         * @param {string} email
         * @param {string} password
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createEmailSession(email: string, password: string): Promise<Models.Session> {
            if (typeof email === 'undefined') {
                throw new AppwriteException('Missing required parameter: "email"');
            }

            if (typeof password === 'undefined') {
                throw new AppwriteException('Missing required parameter: "password"');
            }

            let path = '/account/sessions/email';
            let payload: Payload = {};

            if (typeof email !== 'undefined') {
                payload['email'] = email;
            }

            if (typeof password !== 'undefined') {
                payload['password'] = password;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Magic URL session
         *
         * Sends the user an email with a secret key for creating a session. If the
         * provided user ID has not be registered, a new user will be created. When
         * the user clicks the link in the email, the user is redirected back to the
         * URL you provided with the secret key and userId values attached to the URL
         * query string. Use the query string parameters to submit a request to the
         * [PUT
         * /account/sessions/magic-url](/docs/client/account#accountUpdateMagicURLSession)
         * endpoint to complete the login process. The link sent to the user's email
         * address is valid for 1 hour. If you are on a mobile device you can leave
         * the URL parameter empty, so that the login completion will be handled by
         * your Appwrite instance by default.
         *
         * @param {string} userId
         * @param {string} email
         * @param {string} url
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createMagicURLSession(userId: string, email: string, url?: string): Promise<Models.Token> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof email === 'undefined') {
                throw new AppwriteException('Missing required parameter: "email"');
            }

            let path = '/account/sessions/magic-url';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof email !== 'undefined') {
                payload['email'] = email;
            }

            if (typeof url !== 'undefined') {
                payload['url'] = url;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Magic URL session (confirmation)
         *
         * Use this endpoint to complete creating the session with the Magic URL. Both
         * the **userId** and **secret** arguments will be passed as query parameters
         * to the redirect URL you have provided when sending your request to the
         * [POST
         * /account/sessions/magic-url](/docs/client/account#accountCreateMagicURLSession)
         * endpoint.
         * 
         * Please note that in order to avoid a [Redirect
         * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
         * the only valid redirect URLs are the ones from domains you have set when
         * adding your platforms in the console interface.
         *
         * @param {string} userId
         * @param {string} secret
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateMagicURLSession(userId: string, secret: string): Promise<Models.Session> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof secret === 'undefined') {
                throw new AppwriteException('Missing required parameter: "secret"');
            }

            let path = '/account/sessions/magic-url';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof secret !== 'undefined') {
                payload['secret'] = secret;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('put', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Account Session with OAuth2
         *
         * Allow the user to login to their account using the OAuth2 provider of their
         * choice. Each OAuth2 provider should be enabled from the Appwrite console
         * first. Use the success and failure arguments to provide a redirect URL's
         * back to your app when login is completed.
         * 
         * If there is already an active session, the new session will be attached to
         * the logged-in account. If there are no active sessions, the server will
         * attempt to look for a user with the same email address as the email
         * received from the OAuth2 provider and attach the new session to the
         * existing user. If no matching user is found - the server will create a new
         * user..
         * 
         *
         * @param {string} provider
         * @param {string} success
         * @param {string} failure
         * @param {string[]} scopes
         * @throws {AppwriteException}
         * @returns {void|string}
         */
        createOAuth2Session(provider: string, success?: string, failure?: string, scopes?: string[]): void | URL {
            if (typeof provider === 'undefined') {
                throw new AppwriteException('Missing required parameter: "provider"');
            }

            let path = '/account/sessions/oauth2/{provider}'.replace('{provider}', provider);
            let payload: Payload = {};

            if (typeof success !== 'undefined') {
                payload['success'] = success;
            }

            if (typeof failure !== 'undefined') {
                payload['failure'] = failure;
            }

            if (typeof scopes !== 'undefined') {
                payload['scopes'] = scopes;
            }

            const uri = new URL(this.client.config.endpoint + path);
            payload['project'] = this.client.config.project;


            for (const [key, value] of Object.entries(Service.flatten(payload))) {
                uri.searchParams.append(key, value);
            }
            if (typeof window !== 'undefined' && window?.location) {
                window.location.href = uri.toString();
            } else {
                return uri;
            }
        }

        /**
         * Create Phone session
         *
         * Sends the user an SMS with a secret key for creating a session. If the
         * provided user ID has not be registered, a new user will be created. Use the
         * returned user ID and secret and submit a request to the [PUT
         * /account/sessions/phone](/docs/client/account#accountUpdatePhoneSession)
         * endpoint to complete the login process. The secret sent to the user's phone
         * is valid for 15 minutes.
         *
         * @param {string} userId
         * @param {string} phone
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createPhoneSession(userId: string, phone: string): Promise<Models.Token> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof phone === 'undefined') {
                throw new AppwriteException('Missing required parameter: "phone"');
            }

            let path = '/account/sessions/phone';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof phone !== 'undefined') {
                payload['phone'] = phone;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Phone Session (confirmation)
         *
         * Use this endpoint to complete creating a session with SMS. Use the
         * **userId** from the
         * [createPhoneSession](/docs/client/account#accountCreatePhoneSession)
         * endpoint and the **secret** received via SMS to successfully update and
         * confirm the phone session.
         *
         * @param {string} userId
         * @param {string} secret
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updatePhoneSession(userId: string, secret: string): Promise<Models.Session> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof secret === 'undefined') {
                throw new AppwriteException('Missing required parameter: "secret"');
            }

            let path = '/account/sessions/phone';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof secret !== 'undefined') {
                payload['secret'] = secret;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('put', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Get Session By ID
         *
         * Use this endpoint to get a logged in user's session using a Session ID.
         * Inputting 'current' will return the current session being used.
         *
         * @param {string} sessionId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async getSession(sessionId: string): Promise<Models.Session> {
            if (typeof sessionId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "sessionId"');
            }

            let path = '/account/sessions/{sessionId}'.replace('{sessionId}', sessionId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('get', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Session (Refresh Tokens)
         *
         * Access tokens have limited lifespan and expire to mitigate security risks.
         * If session was created using an OAuth provider, this route can be used to
         * "refresh" the access token.
         *
         * @param {string} sessionId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateSession(sessionId: string): Promise<Models.Session> {
            if (typeof sessionId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "sessionId"');
            }

            let path = '/account/sessions/{sessionId}'.replace('{sessionId}', sessionId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Delete Account Session
         *
         * Use this endpoint to log out the currently logged in user from all their
         * account sessions across all of their different devices. When using the
         * Session ID argument, only the unique session ID provided is deleted.
         * 
         *
         * @param {string} sessionId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async deleteSession(sessionId: string): Promise<{}> {
            if (typeof sessionId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "sessionId"');
            }

            let path = '/account/sessions/{sessionId}'.replace('{sessionId}', sessionId);
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('delete', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Update Account Status
         *
         * Block the currently logged in user account. Behind the scene, the user
         * record is not deleted but permanently blocked from any access. To
         * completely delete a user, use the Users API instead.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateStatus<Preferences extends Models.Preferences>(): Promise<Models.Account<Preferences>> {
            let path = '/account/status';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('patch', uri, {
                'content-type': 'application/json',
            }, payload);
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
         * process](/docs/client/account#accountUpdateEmailVerification). The
         * verification link sent to the user's email address is valid for 7 days.
         * 
         * Please note that in order to avoid a [Redirect
         * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md),
         * the only valid redirect URLs are the ones from domains you have set when
         * adding your platforms in the console interface.
         * 
         *
         * @param {string} url
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createVerification(url: string): Promise<Models.Token> {
            if (typeof url === 'undefined') {
                throw new AppwriteException('Missing required parameter: "url"');
            }

            let path = '/account/verification';
            let payload: Payload = {};

            if (typeof url !== 'undefined') {
                payload['url'] = url;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Email Verification (confirmation)
         *
         * Use this endpoint to complete the user email verification process. Use both
         * the **userId** and **secret** parameters that were attached to your app URL
         * to verify the user email ownership. If confirmed this route will return a
         * 200 status code.
         *
         * @param {string} userId
         * @param {string} secret
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updateVerification(userId: string, secret: string): Promise<Models.Token> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof secret === 'undefined') {
                throw new AppwriteException('Missing required parameter: "secret"');
            }

            let path = '/account/verification';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof secret !== 'undefined') {
                payload['secret'] = secret;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('put', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Phone Verification
         *
         * Use this endpoint to send a verification SMS to the currently logged in
         * user. This endpoint is meant for use after updating a user's phone number
         * using the [accountUpdatePhone](/docs/client/account#accountUpdatePhone)
         * endpoint. Learn more about how to [complete the verification
         * process](/docs/client/account#accountUpdatePhoneVerification). The
         * verification code sent to the user's phone number is valid for 15 minutes.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async createPhoneVerification(): Promise<Models.Token> {
            let path = '/account/verification/phone';
            let payload: Payload = {};

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('post', uri, {
                'content-type': 'application/json',
            }, payload);
        }

        /**
         * Create Phone Verification (confirmation)
         *
         * Use this endpoint to complete the user phone verification process. Use the
         * **userId** and **secret** that were sent to your user's phone number to
         * verify the user email ownership. If confirmed this route will return a 200
         * status code.
         *
         * @param {string} userId
         * @param {string} secret
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        async updatePhoneVerification(userId: string, secret: string): Promise<Models.Token> {
            if (typeof userId === 'undefined') {
                throw new AppwriteException('Missing required parameter: "userId"');
            }

            if (typeof secret === 'undefined') {
                throw new AppwriteException('Missing required parameter: "secret"');
            }

            let path = '/account/verification/phone';
            let payload: Payload = {};

            if (typeof userId !== 'undefined') {
                payload['userId'] = userId;
            }

            if (typeof secret !== 'undefined') {
                payload['secret'] = secret;
            }

            const uri = new URL(this.client.config.endpoint + path);
            return await this.client.call('put', uri, {
                'content-type': 'application/json',
            }, payload);
        }
};
