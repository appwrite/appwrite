(function (exports, isomorphicFormData, crossFetch) {
    'use strict';

    /*! *****************************************************************************
    Copyright (c) Microsoft Corporation.

    Permission to use, copy, modify, and/or distribute this software for any
    purpose with or without fee is hereby granted.

    THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
    REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
    INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM
    LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR
    OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR
    PERFORMANCE OF THIS SOFTWARE.
    ***************************************************************************** */

    function __awaiter(thisArg, _arguments, P, generator) {
        function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
        return new (P || (P = Promise))(function (resolve, reject) {
            function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
            function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
            function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
            step((generator = generator.apply(thisArg, _arguments || [])).next());
        });
    }

    class AppwriteException extends Error {
        constructor(message, code = 0, response = '') {
            super(message);
            this.name = 'AppwriteException';
            this.message = message;
            this.code = code;
            this.response = response;
        }
    }
    class Appwrite {
        constructor() {
            this.config = {
                endpoint: 'https://appwrite.io/v1',
                project: '',
                key: '',
                jwt: '',
                locale: '',
                mode: '',
            };
            this.headers = {
                'x-sdk-version': 'appwrite:web:2.1.0',
                'X-Appwrite-Response-Format': '0.9.0',
            };
            this.account = {
                /**
                 * Get Account
                 *
                 * Get currently logged in user data as JSON object.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
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
                 * @param {string} email
                 * @param {string} password
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                create: (email, password, name) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof email === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (typeof password === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/account';
                    let payload = {};
                    if (typeof email !== 'undefined') {
                        payload['email'] = email;
                    }
                    if (typeof password !== 'undefined') {
                        payload['password'] = password;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Account
                 *
                 * Delete a currently logged in user account. Behind the scene, the user
                 * record is not deleted but permanently blocked from any access. This is done
                 * to avoid deleted accounts being overtaken by new users with the same email
                 * address. Any user-related resources like documents or storage files should
                 * be deleted separately.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                delete: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Account Email
                 *
                 * Update currently logged in user account email address. After changing user
                 * address, user confirmation status is being reset and a new confirmation
                 * mail is sent. For security measures, user password is required to complete
                 * this request.
                 * This endpoint can also be used to convert an anonymous account to a normal
                 * one, by passing an email address and a new password.
                 *
                 * @param {string} email
                 * @param {string} password
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateEmail: (email, password) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof email === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (typeof password === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/account/email';
                    let payload = {};
                    if (typeof email !== 'undefined') {
                        payload['email'] = email;
                    }
                    if (typeof password !== 'undefined') {
                        payload['password'] = password;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
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
                createJWT: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/jwt';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Account Logs
                 *
                 * Get currently logged in user list of latest security activity logs. Each
                 * log returns user IP address, location and date and time of log.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getLogs: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/logs';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Account Name
                 *
                 * Update currently logged in user account name.
                 *
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateName: (name) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/account/name';
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Account Password
                 *
                 * Update currently logged in user password. For validation, user is required
                 * to pass in the new password, and the old password. For users created with
                 * OAuth and Team Invites, oldPassword is optional.
                 *
                 * @param {string} password
                 * @param {string} oldPassword
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updatePassword: (password, oldPassword) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof password === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/account/password';
                    let payload = {};
                    if (typeof password !== 'undefined') {
                        payload['password'] = password;
                    }
                    if (typeof oldPassword !== 'undefined') {
                        payload['oldPassword'] = oldPassword;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Account Preferences
                 *
                 * Get currently logged in user preferences as a key-value object.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getPrefs: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/prefs';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Account Preferences
                 *
                 * Update currently logged in user account preferences. You can pass only the
                 * specific settings you wish to update.
                 *
                 * @param {object} prefs
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updatePrefs: (prefs) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof prefs === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "prefs"');
                    }
                    let path = '/account/prefs';
                    let payload = {};
                    if (typeof prefs !== 'undefined') {
                        payload['prefs'] = prefs;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
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
                createRecovery: (email, url) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof email === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/account/recovery';
                    let payload = {};
                    if (typeof email !== 'undefined') {
                        payload['email'] = email;
                    }
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Complete Password Recovery
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
                updateRecovery: (userId, secret, password, passwordAgain) => __awaiter(this, void 0, void 0, function* () {
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
                    let payload = {};
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
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Account Sessions
                 *
                 * Get currently logged in user list of active sessions across different
                 * devices.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getSessions: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/sessions';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Account Session
                 *
                 * Allow the user to login into their account by providing a valid email and
                 * password combination. This route will create a new session for the user.
                 *
                 * @param {string} email
                 * @param {string} password
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createSession: (email, password) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof email === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (typeof password === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/account/sessions';
                    let payload = {};
                    if (typeof email !== 'undefined') {
                        payload['email'] = email;
                    }
                    if (typeof password !== 'undefined') {
                        payload['password'] = password;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete All Account Sessions
                 *
                 * Delete all sessions from the user account and remove any sessions cookies
                 * from the end client.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteSessions: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/sessions';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
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
                createAnonymousSession: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/sessions/anonymous';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
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
                createOAuth2Session: (provider, success, failure, scopes) => {
                    if (typeof provider === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "provider"');
                    }
                    let path = '/account/sessions/oauth2/{provider}'.replace('{provider}', provider);
                    let payload = {};
                    if (typeof success !== 'undefined') {
                        payload['success'] = success;
                    }
                    if (typeof failure !== 'undefined') {
                        payload['failure'] = failure;
                    }
                    if (typeof scopes !== 'undefined') {
                        payload['scopes'] = scopes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    if (typeof window !== 'undefined' && (window === null || window === void 0 ? void 0 : window.location)) {
                        window.location.href = uri.toString();
                    }
                    else {
                        return uri;
                    }
                },
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
                getSession: (sessionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof sessionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "sessionId"');
                    }
                    let path = '/account/sessions/{sessionId}'.replace('{sessionId}', sessionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Account Session
                 *
                 * Use this endpoint to log out the currently logged in user from all their
                 * account sessions across all of their different devices. When using the
                 * option id argument, only the session unique ID provider will be deleted.
                 *
                 * @param {string} sessionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteSession: (sessionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof sessionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "sessionId"');
                    }
                    let path = '/account/sessions/{sessionId}'.replace('{sessionId}', sessionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
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
                 * process](/docs/client/account#accountUpdateVerification). The verification
                 * link sent to the user's email address is valid for 7 days.
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
                createVerification: (url) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/account/verification';
                    let payload = {};
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Complete Email Verification
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
                updateVerification: (userId, secret) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof secret === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "secret"');
                    }
                    let path = '/account/verification';
                    let payload = {};
                    if (typeof userId !== 'undefined') {
                        payload['userId'] = userId;
                    }
                    if (typeof secret !== 'undefined') {
                        payload['secret'] = secret;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.avatars = {
                /**
                 * Get Browser Icon
                 *
                 * You can use this endpoint to show different browser icons to your users.
                 * The code argument receives the browser code as it appears in your user
                 * /account/sessions endpoint. Use width, height and quality arguments to
                 * change the output settings.
                 *
                 * @param {string} code
                 * @param {number} width
                 * @param {number} height
                 * @param {number} quality
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getBrowser: (code, width, height, quality) => {
                    if (typeof code === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/avatars/browsers/{code}'.replace('{code}', code);
                    let payload = {};
                    if (typeof width !== 'undefined') {
                        payload['width'] = width;
                    }
                    if (typeof height !== 'undefined') {
                        payload['height'] = height;
                    }
                    if (typeof quality !== 'undefined') {
                        payload['quality'] = quality;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get Credit Card Icon
                 *
                 * The credit card endpoint will return you the icon of the credit card
                 * provider you need. Use width, height and quality arguments to change the
                 * output settings.
                 *
                 * @param {string} code
                 * @param {number} width
                 * @param {number} height
                 * @param {number} quality
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getCreditCard: (code, width, height, quality) => {
                    if (typeof code === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/avatars/credit-cards/{code}'.replace('{code}', code);
                    let payload = {};
                    if (typeof width !== 'undefined') {
                        payload['width'] = width;
                    }
                    if (typeof height !== 'undefined') {
                        payload['height'] = height;
                    }
                    if (typeof quality !== 'undefined') {
                        payload['quality'] = quality;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get Favicon
                 *
                 * Use this endpoint to fetch the favorite icon (AKA favicon) of any remote
                 * website URL.
                 *
                 *
                 * @param {string} url
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getFavicon: (url) => {
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/avatars/favicon';
                    let payload = {};
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get Country Flag
                 *
                 * You can use this endpoint to show different country flags icons to your
                 * users. The code argument receives the 2 letter country code. Use width,
                 * height and quality arguments to change the output settings.
                 *
                 * @param {string} code
                 * @param {number} width
                 * @param {number} height
                 * @param {number} quality
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getFlag: (code, width, height, quality) => {
                    if (typeof code === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/avatars/flags/{code}'.replace('{code}', code);
                    let payload = {};
                    if (typeof width !== 'undefined') {
                        payload['width'] = width;
                    }
                    if (typeof height !== 'undefined') {
                        payload['height'] = height;
                    }
                    if (typeof quality !== 'undefined') {
                        payload['quality'] = quality;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get Image from URL
                 *
                 * Use this endpoint to fetch a remote image URL and crop it to any image size
                 * you want. This endpoint is very useful if you need to crop and display
                 * remote images in your app or in case you want to make sure a 3rd party
                 * image is properly served using a TLS protocol.
                 *
                 * @param {string} url
                 * @param {number} width
                 * @param {number} height
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getImage: (url, width, height) => {
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/avatars/image';
                    let payload = {};
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    if (typeof width !== 'undefined') {
                        payload['width'] = width;
                    }
                    if (typeof height !== 'undefined') {
                        payload['height'] = height;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get User Initials
                 *
                 * Use this endpoint to show your user initials avatar icon on your website or
                 * app. By default, this route will try to print your logged-in user name or
                 * email initials. You can also overwrite the user name if you pass the 'name'
                 * parameter. If no name is given and no user is logged, an empty avatar will
                 * be returned.
                 *
                 * You can use the color and background params to change the avatar colors. By
                 * default, a random theme will be selected. The random theme will persist for
                 * the user's initials when reloading the same theme will always return for
                 * the same initials.
                 *
                 * @param {string} name
                 * @param {number} width
                 * @param {number} height
                 * @param {string} color
                 * @param {string} background
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getInitials: (name, width, height, color, background) => {
                    let path = '/avatars/initials';
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof width !== 'undefined') {
                        payload['width'] = width;
                    }
                    if (typeof height !== 'undefined') {
                        payload['height'] = height;
                    }
                    if (typeof color !== 'undefined') {
                        payload['color'] = color;
                    }
                    if (typeof background !== 'undefined') {
                        payload['background'] = background;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get QR Code
                 *
                 * Converts a given plain text to a QR code image. You can use the query
                 * parameters to change the size and style of the resulting image.
                 *
                 * @param {string} text
                 * @param {number} size
                 * @param {number} margin
                 * @param {boolean} download
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getQR: (text, size, margin, download) => {
                    if (typeof text === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "text"');
                    }
                    let path = '/avatars/qr';
                    let payload = {};
                    if (typeof text !== 'undefined') {
                        payload['text'] = text;
                    }
                    if (typeof size !== 'undefined') {
                        payload['size'] = size;
                    }
                    if (typeof margin !== 'undefined') {
                        payload['margin'] = margin;
                    }
                    if (typeof download !== 'undefined') {
                        payload['download'] = download;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                }
            };
            this.database = {
                /**
                 * List Collections
                 *
                 * Get a list of all the user collections. You can use the query params to
                 * filter your results. On admin mode, this endpoint will return a list of all
                 * of the project's collections. [Learn more about different API
                 * modes](/docs/admin).
                 *
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listCollections: (search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    let path = '/database/collections';
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Collection
                 *
                 * Create a new Collection.
                 *
                 * @param {string} collectionId
                 * @param {string} name
                 * @param {string} read
                 * @param {string} write
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createCollection: (collectionId, name, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof read === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (typeof write === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    let path = '/database/collections';
                    let payload = {};
                    if (typeof collectionId !== 'undefined') {
                        payload['collectionId'] = collectionId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Collection
                 *
                 * Get a collection by its unique ID. This endpoint response returns a JSON
                 * object with the collection metadata.
                 *
                 * @param {string} collectionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getCollection: (collectionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}'.replace('{collectionId}', collectionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Collection
                 *
                 * Update a collection by its unique ID.
                 *
                 * @param {string} collectionId
                 * @param {string} name
                 * @param {string} read
                 * @param {string} write
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateCollection: (collectionId, name, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/database/collections/{collectionId}'.replace('{collectionId}', collectionId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Collection
                 *
                 * Delete a collection by its unique ID. Only users with write permissions
                 * have access to delete this resource.
                 *
                 * @param {string} collectionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteCollection: (collectionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}'.replace('{collectionId}', collectionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Attributes
                 *
                 *
                 * @param {string} collectionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listAttributes: (collectionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}/attributes'.replace('{collectionId}', collectionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Attribute
                 *
                 *
                 * @param {string} collectionId
                 * @param {string} id
                 * @param {string} type
                 * @param {number} size
                 * @param {boolean} required
                 * @param {string} xdefault
                 * @param {boolean} array
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createAttribute: (collectionId, id, type, size, required, xdefault, array) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof id === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "id"');
                    }
                    if (typeof type === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "type"');
                    }
                    if (typeof size === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "size"');
                    }
                    if (typeof required === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "required"');
                    }
                    let path = '/database/collections/{collectionId}/attributes'.replace('{collectionId}', collectionId);
                    let payload = {};
                    if (typeof id !== 'undefined') {
                        payload['id'] = id;
                    }
                    if (typeof type !== 'undefined') {
                        payload['type'] = type;
                    }
                    if (typeof size !== 'undefined') {
                        payload['size'] = size;
                    }
                    if (typeof required !== 'undefined') {
                        payload['required'] = required;
                    }
                    if (typeof xdefault !== 'undefined') {
                        payload['xdefault'] = xdefault;
                    }
                    if (typeof array !== 'undefined') {
                        payload['array'] = array;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Attribute
                 *
                 *
                 * @param {string} collectionId
                 * @param {string} attributeId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getAttribute: (collectionId, attributeId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof attributeId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "attributeId"');
                    }
                    let path = '/database/collections/{collectionId}/attributes/{attributeId}'.replace('{collectionId}', collectionId).replace('{attributeId}', attributeId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Attribute
                 *
                 *
                 * @param {string} collectionId
                 * @param {string} attributeId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteAttribute: (collectionId, attributeId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof attributeId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "attributeId"');
                    }
                    let path = '/database/collections/{collectionId}/attributes/{attributeId}'.replace('{collectionId}', collectionId).replace('{attributeId}', attributeId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Documents
                 *
                 * Get a list of all the user documents. You can use the query params to
                 * filter your results. On admin mode, this endpoint will return a list of all
                 * of the project's documents. [Learn more about different API
                 * modes](/docs/admin).
                 *
                 * @param {string} collectionId
                 * @param {string[]} queries
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string[]} orderAttributes
                 * @param {string[]} orderTypes
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listDocuments: (collectionId, queries, limit, offset, orderAttributes, orderTypes) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}/documents'.replace('{collectionId}', collectionId);
                    let payload = {};
                    if (typeof queries !== 'undefined') {
                        payload['queries'] = queries;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderAttributes !== 'undefined') {
                        payload['orderAttributes'] = orderAttributes;
                    }
                    if (typeof orderTypes !== 'undefined') {
                        payload['orderTypes'] = orderTypes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Document
                 *
                 * Create a new Document. Before using this route, you should create a new
                 * collection resource using either a [server
                 * integration](/docs/server/database#databaseCreateCollection) API or
                 * directly from your database console.
                 *
                 * @param {string} collectionId
                 * @param {string} documentId
                 * @param {object} data
                 * @param {string} read
                 * @param {string} write
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createDocument: (collectionId, documentId, data, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof documentId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    if (typeof data === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "data"');
                    }
                    let path = '/database/collections/{collectionId}/documents'.replace('{collectionId}', collectionId);
                    let payload = {};
                    if (typeof documentId !== 'undefined') {
                        payload['documentId'] = documentId;
                    }
                    if (typeof data !== 'undefined') {
                        payload['data'] = data;
                    }
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Document
                 *
                 * Get a document by its unique ID. This endpoint response returns a JSON
                 * object with the document data.
                 *
                 * @param {string} collectionId
                 * @param {string} documentId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getDocument: (collectionId, documentId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof documentId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    let path = '/database/collections/{collectionId}/documents/{documentId}'.replace('{collectionId}', collectionId).replace('{documentId}', documentId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Document
                 *
                 * Update a document by its unique ID. Using the patch method you can pass
                 * only specific fields that will get updated.
                 *
                 * @param {string} collectionId
                 * @param {string} documentId
                 * @param {object} data
                 * @param {string} read
                 * @param {string} write
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateDocument: (collectionId, documentId, data, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof documentId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    if (typeof data === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "data"');
                    }
                    let path = '/database/collections/{collectionId}/documents/{documentId}'.replace('{collectionId}', collectionId).replace('{documentId}', documentId);
                    let payload = {};
                    if (typeof data !== 'undefined') {
                        payload['data'] = data;
                    }
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Document
                 *
                 * Delete a document by its unique ID. This endpoint deletes only the parent
                 * documents, its attributes and relations to other documents. Child documents
                 * **will not** be deleted.
                 *
                 * @param {string} collectionId
                 * @param {string} documentId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteDocument: (collectionId, documentId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof documentId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    let path = '/database/collections/{collectionId}/documents/{documentId}'.replace('{collectionId}', collectionId).replace('{documentId}', documentId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Indexes
                 *
                 *
                 * @param {string} collectionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listIndexes: (collectionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}/indexes'.replace('{collectionId}', collectionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Index
                 *
                 *
                 * @param {string} collectionId
                 * @param {string} id
                 * @param {string} type
                 * @param {string[]} attributes
                 * @param {string[]} orders
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createIndex: (collectionId, id, type, attributes, orders) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof id === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "id"');
                    }
                    if (typeof type === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "type"');
                    }
                    if (typeof attributes === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "attributes"');
                    }
                    let path = '/database/collections/{collectionId}/indexes'.replace('{collectionId}', collectionId);
                    let payload = {};
                    if (typeof id !== 'undefined') {
                        payload['id'] = id;
                    }
                    if (typeof type !== 'undefined') {
                        payload['type'] = type;
                    }
                    if (typeof attributes !== 'undefined') {
                        payload['attributes'] = attributes;
                    }
                    if (typeof orders !== 'undefined') {
                        payload['orders'] = orders;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Index
                 *
                 *
                 * @param {string} collectionId
                 * @param {string} indexId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getIndex: (collectionId, indexId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof indexId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "indexId"');
                    }
                    let path = '/database/collections/{collectionId}/indexes/{indexId}'.replace('{collectionId}', collectionId).replace('{indexId}', indexId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Index
                 *
                 *
                 * @param {string} collectionId
                 * @param {string} indexId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteIndex: (collectionId, indexId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof collectionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (typeof indexId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "indexId"');
                    }
                    let path = '/database/collections/{collectionId}/indexes/{indexId}'.replace('{collectionId}', collectionId).replace('{indexId}', indexId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.functions = {
                /**
                 * List Functions
                 *
                 * Get a list of all the project's functions. You can use the query params to
                 * filter your results.
                 *
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                list: (search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    let path = '/functions';
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Function
                 *
                 * Create a new function. You can pass a list of
                 * [permissions](/docs/permissions) to allow different project users or team
                 * with access to execute the function using the client API.
                 *
                 * @param {string} functionId
                 * @param {string} name
                 * @param {string[]} execute
                 * @param {string} runtime
                 * @param {object} vars
                 * @param {string[]} events
                 * @param {string} schedule
                 * @param {number} timeout
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                create: (functionId, name, execute, runtime, vars, events, schedule, timeout) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof execute === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "execute"');
                    }
                    if (typeof runtime === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "runtime"');
                    }
                    let path = '/functions';
                    let payload = {};
                    if (typeof functionId !== 'undefined') {
                        payload['functionId'] = functionId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof execute !== 'undefined') {
                        payload['execute'] = execute;
                    }
                    if (typeof runtime !== 'undefined') {
                        payload['runtime'] = runtime;
                    }
                    if (typeof vars !== 'undefined') {
                        payload['vars'] = vars;
                    }
                    if (typeof events !== 'undefined') {
                        payload['events'] = events;
                    }
                    if (typeof schedule !== 'undefined') {
                        payload['schedule'] = schedule;
                    }
                    if (typeof timeout !== 'undefined') {
                        payload['timeout'] = timeout;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Function
                 *
                 * Get a function by its unique ID.
                 *
                 * @param {string} functionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: (functionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}'.replace('{functionId}', functionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Function
                 *
                 * Update function by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} name
                 * @param {string[]} execute
                 * @param {object} vars
                 * @param {string[]} events
                 * @param {string} schedule
                 * @param {number} timeout
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                update: (functionId, name, execute, vars, events, schedule, timeout) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof execute === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "execute"');
                    }
                    let path = '/functions/{functionId}'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof execute !== 'undefined') {
                        payload['execute'] = execute;
                    }
                    if (typeof vars !== 'undefined') {
                        payload['vars'] = vars;
                    }
                    if (typeof events !== 'undefined') {
                        payload['events'] = events;
                    }
                    if (typeof schedule !== 'undefined') {
                        payload['schedule'] = schedule;
                    }
                    if (typeof timeout !== 'undefined') {
                        payload['timeout'] = timeout;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Function
                 *
                 * Delete a function by its unique ID.
                 *
                 * @param {string} functionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                delete: (functionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}'.replace('{functionId}', functionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Executions
                 *
                 * Get a list of all the current user function execution logs. You can use the
                 * query params to filter your results. On admin mode, this endpoint will
                 * return a list of all of the project's executions. [Learn more about
                 * different API modes](/docs/admin).
                 *
                 * @param {string} functionId
                 * @param {number} limit
                 * @param {number} offset
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listExecutions: (functionId, limit, offset) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/executions'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Execution
                 *
                 * Trigger a function execution. The returned object will return you the
                 * current execution status. You can ping the `Get Execution` endpoint to get
                 * updates on the current execution status. Once this endpoint is called, your
                 * function execution process will start asynchronously.
                 *
                 * @param {string} functionId
                 * @param {string} executionId
                 * @param {string} data
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createExecution: (functionId, executionId, data) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof executionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "executionId"');
                    }
                    let path = '/functions/{functionId}/executions'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof executionId !== 'undefined') {
                        payload['executionId'] = executionId;
                    }
                    if (typeof data !== 'undefined') {
                        payload['data'] = data;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Execution
                 *
                 * Get a function execution log by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} executionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getExecution: (functionId, executionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof executionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "executionId"');
                    }
                    let path = '/functions/{functionId}/executions/{executionId}'.replace('{functionId}', functionId).replace('{executionId}', executionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Function Tag
                 *
                 * Update the function code tag ID using the unique function ID. Use this
                 * endpoint to switch the code tag that should be executed by the execution
                 * endpoint.
                 *
                 * @param {string} functionId
                 * @param {string} tag
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateTag: (functionId, tag) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof tag === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "tag"');
                    }
                    let path = '/functions/{functionId}/tag'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof tag !== 'undefined') {
                        payload['tag'] = tag;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Tags
                 *
                 * Get a list of all the project's code tags. You can use the query params to
                 * filter your results.
                 *
                 * @param {string} functionId
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listTags: (functionId, search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/tags'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Tag
                 *
                 * Create a new function code tag. Use this endpoint to upload a new version
                 * of your code function. To execute your newly uploaded code, you'll need to
                 * update the function's tag to use your new tag UID.
                 *
                 * This endpoint accepts a tar.gz file compressed with your code. Make sure to
                 * include any dependencies your code has within the compressed file. You can
                 * learn more about code packaging in the [Appwrite Cloud Functions
                 * tutorial](/docs/functions).
                 *
                 * Use the "command" param to set the entry point used to execute your code.
                 *
                 * @param {string} tagId
                 * @param {string} functionId
                 * @param {string} command
                 * @param {File} code
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createTag: (tagId, functionId, command, code) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof tagId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "tagId"');
                    }
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof command === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "command"');
                    }
                    if (typeof code === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/functions/{functionId}/tags'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof tagId !== 'undefined') {
                        payload['tagId'] = tagId;
                    }
                    if (typeof command !== 'undefined') {
                        payload['command'] = command;
                    }
                    if (typeof code !== 'undefined') {
                        payload['code'] = code;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'multipart/form-data',
                    }, payload);
                }),
                /**
                 * Get Tag
                 *
                 * Get a code tag by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} tagId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getTag: (functionId, tagId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof tagId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "tagId"');
                    }
                    let path = '/functions/{functionId}/tags/{tagId}'.replace('{functionId}', functionId).replace('{tagId}', tagId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Tag
                 *
                 * Delete a code tag by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} tagId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteTag: (functionId, tagId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (typeof tagId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "tagId"');
                    }
                    let path = '/functions/{functionId}/tags/{tagId}'.replace('{functionId}', functionId).replace('{tagId}', tagId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Function Usage
                 *
                 *
                 * @param {string} functionId
                 * @param {string} range
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getUsage: (functionId, range) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof functionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/usage'.replace('{functionId}', functionId);
                    let payload = {};
                    if (typeof range !== 'undefined') {
                        payload['range'] = range;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.health = {
                /**
                 * Get HTTP
                 *
                 * Check the Appwrite HTTP server is up and responsive.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Anti virus
                 *
                 * Check the Appwrite Anti Virus server is up and connection is successful.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getAntiVirus: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/anti-virus';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Cache
                 *
                 * Check the Appwrite in-memory cache server is up and connection is
                 * successful.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getCache: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/cache';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get DB
                 *
                 * Check the Appwrite database server is up and connection is successful.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getDB: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/db';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Certificate Queue
                 *
                 * Get the number of certificates that are waiting to be issued against
                 * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
                 * server.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getQueueCertificates: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/certificates';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Functions Queue
                 *
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getQueueFunctions: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/functions';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Logs Queue
                 *
                 * Get the number of logs that are waiting to be processed in the Appwrite
                 * internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getQueueLogs: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/logs';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Tasks Queue
                 *
                 * Get the number of tasks that are waiting to be processed in the Appwrite
                 * internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getQueueTasks: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/tasks';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Usage Queue
                 *
                 * Get the number of usage stats that are waiting to be processed in the
                 * Appwrite internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getQueueUsage: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/usage';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Webhooks Queue
                 *
                 * Get the number of webhooks that are waiting to be processed in the Appwrite
                 * internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getQueueWebhooks: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/webhooks';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Local Storage
                 *
                 * Check the Appwrite local storage device is up and connection is successful.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getStorageLocal: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/storage/local';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Time
                 *
                 * Check the Appwrite server time is synced with Google remote NTP server. We
                 * use this technology to smoothly handle leap seconds with no disruptive
                 * events. The [Network Time
                 * Protocol](https://en.wikipedia.org/wiki/Network_Time_Protocol) (NTP) is
                 * used by hundreds of millions of computers and devices to synchronize their
                 * clocks over the Internet. If your computer sets its own clock, it likely
                 * uses NTP.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getTime: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/time';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.locale = {
                /**
                 * Get User Locale
                 *
                 * Get the current user location based on IP. Returns an object with user
                 * country code, country name, continent name, continent code, ip address and
                 * suggested currency. You can use the locale header to get the data in a
                 * supported language.
                 *
                 * ([IP Geolocation by DB-IP](https://db-ip.com))
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Continents
                 *
                 * List of all continents. You can use the locale header to get the data in a
                 * supported language.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getContinents: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/continents';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Countries
                 *
                 * List of all countries. You can use the locale header to get the data in a
                 * supported language.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getCountries: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/countries';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List EU Countries
                 *
                 * List of all countries that are currently members of the EU. You can use the
                 * locale header to get the data in a supported language.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getCountriesEU: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/countries/eu';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Countries Phone Codes
                 *
                 * List of all countries phone codes. You can use the locale header to get the
                 * data in a supported language.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getCountriesPhones: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/countries/phones';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Currencies
                 *
                 * List of all currencies, including currency symbol, name, plural, and
                 * decimal digits for all major and minor currencies. You can use the locale
                 * header to get the data in a supported language.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getCurrencies: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/currencies';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Languages
                 *
                 * List of all languages classified by ISO 639-1 including 2-letter code, name
                 * in English, and name in the respective language.
                 *
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getLanguages: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/languages';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.projects = {
                /**
                 * List Projects
                 *
                 *
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                list: (search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    let path = '/projects';
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Project
                 *
                 *
                 * @param {string} projectId
                 * @param {string} name
                 * @param {string} teamId
                 * @param {string} description
                 * @param {string} logo
                 * @param {string} url
                 * @param {string} legalName
                 * @param {string} legalCountry
                 * @param {string} legalState
                 * @param {string} legalCity
                 * @param {string} legalAddress
                 * @param {string} legalTaxId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                create: (projectId, name, teamId, description, logo, url, legalName, legalCountry, legalState, legalCity, legalAddress, legalTaxId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/projects';
                    let payload = {};
                    if (typeof projectId !== 'undefined') {
                        payload['projectId'] = projectId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof teamId !== 'undefined') {
                        payload['teamId'] = teamId;
                    }
                    if (typeof description !== 'undefined') {
                        payload['description'] = description;
                    }
                    if (typeof logo !== 'undefined') {
                        payload['logo'] = logo;
                    }
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    if (typeof legalName !== 'undefined') {
                        payload['legalName'] = legalName;
                    }
                    if (typeof legalCountry !== 'undefined') {
                        payload['legalCountry'] = legalCountry;
                    }
                    if (typeof legalState !== 'undefined') {
                        payload['legalState'] = legalState;
                    }
                    if (typeof legalCity !== 'undefined') {
                        payload['legalCity'] = legalCity;
                    }
                    if (typeof legalAddress !== 'undefined') {
                        payload['legalAddress'] = legalAddress;
                    }
                    if (typeof legalTaxId !== 'undefined') {
                        payload['legalTaxId'] = legalTaxId;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Project
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}'.replace('{projectId}', projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Project
                 *
                 *
                 * @param {string} projectId
                 * @param {string} name
                 * @param {string} description
                 * @param {string} logo
                 * @param {string} url
                 * @param {string} legalName
                 * @param {string} legalCountry
                 * @param {string} legalState
                 * @param {string} legalCity
                 * @param {string} legalAddress
                 * @param {string} legalTaxId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                update: (projectId, name, description, logo, url, legalName, legalCountry, legalState, legalCity, legalAddress, legalTaxId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/projects/{projectId}'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof description !== 'undefined') {
                        payload['description'] = description;
                    }
                    if (typeof logo !== 'undefined') {
                        payload['logo'] = logo;
                    }
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    if (typeof legalName !== 'undefined') {
                        payload['legalName'] = legalName;
                    }
                    if (typeof legalCountry !== 'undefined') {
                        payload['legalCountry'] = legalCountry;
                    }
                    if (typeof legalState !== 'undefined') {
                        payload['legalState'] = legalState;
                    }
                    if (typeof legalCity !== 'undefined') {
                        payload['legalCity'] = legalCity;
                    }
                    if (typeof legalAddress !== 'undefined') {
                        payload['legalAddress'] = legalAddress;
                    }
                    if (typeof legalTaxId !== 'undefined') {
                        payload['legalTaxId'] = legalTaxId;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Project
                 *
                 *
                 * @param {string} projectId
                 * @param {string} password
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                delete: (projectId, password) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof password === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/projects/{projectId}'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof password !== 'undefined') {
                        payload['password'] = password;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Project users limit
                 *
                 *
                 * @param {string} projectId
                 * @param {number} limit
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateAuthLimit: (projectId, limit) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof limit === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "limit"');
                    }
                    let path = '/projects/{projectId}/auth/limit'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Project auth method status. Use this endpoint to enable or disable a given auth method for this project.
                 *
                 *
                 * @param {string} projectId
                 * @param {string} method
                 * @param {boolean} status
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateAuthStatus: (projectId, method, status) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof method === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "method"');
                    }
                    if (typeof status === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    let path = '/projects/{projectId}/auth/{method}'.replace('{projectId}', projectId).replace('{method}', method);
                    let payload = {};
                    if (typeof status !== 'undefined') {
                        payload['status'] = status;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Domains
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listDomains: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/domains'.replace('{projectId}', projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Domain
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @param {string} domain
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createDomain: (projectId, domainId, domain) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof domainId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    if (typeof domain === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "domain"');
                    }
                    let path = '/projects/{projectId}/domains'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof domainId !== 'undefined') {
                        payload['domainId'] = domainId;
                    }
                    if (typeof domain !== 'undefined') {
                        payload['domain'] = domain;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Domain
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getDomain: (projectId, domainId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof domainId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    let path = '/projects/{projectId}/domains/{domainId}'.replace('{projectId}', projectId).replace('{domainId}', domainId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Domain
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteDomain: (projectId, domainId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof domainId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    let path = '/projects/{projectId}/domains/{domainId}'.replace('{projectId}', projectId).replace('{domainId}', domainId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Domain Verification Status
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateDomainVerification: (projectId, domainId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof domainId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    let path = '/projects/{projectId}/domains/{domainId}/verification'.replace('{projectId}', projectId).replace('{domainId}', domainId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Keys
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listKeys: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/keys'.replace('{projectId}', projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} keyId
                 * @param {string} name
                 * @param {string[]} scopes
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createKey: (projectId, keyId, name, scopes) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof keyId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof scopes === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "scopes"');
                    }
                    let path = '/projects/{projectId}/keys'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof keyId !== 'undefined') {
                        payload['keyId'] = keyId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof scopes !== 'undefined') {
                        payload['scopes'] = scopes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} keyId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getKey: (projectId, keyId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof keyId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    let path = '/projects/{projectId}/keys/{keyId}'.replace('{projectId}', projectId).replace('{keyId}', keyId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} keyId
                 * @param {string} name
                 * @param {string[]} scopes
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateKey: (projectId, keyId, name, scopes) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof keyId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof scopes === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "scopes"');
                    }
                    let path = '/projects/{projectId}/keys/{keyId}'.replace('{projectId}', projectId).replace('{keyId}', keyId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof scopes !== 'undefined') {
                        payload['scopes'] = scopes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} keyId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteKey: (projectId, keyId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof keyId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    let path = '/projects/{projectId}/keys/{keyId}'.replace('{projectId}', projectId).replace('{keyId}', keyId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Project OAuth2
                 *
                 *
                 * @param {string} projectId
                 * @param {string} provider
                 * @param {string} appId
                 * @param {string} secret
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateOAuth2: (projectId, provider, appId, secret) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof provider === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "provider"');
                    }
                    let path = '/projects/{projectId}/oauth2'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof provider !== 'undefined') {
                        payload['provider'] = provider;
                    }
                    if (typeof appId !== 'undefined') {
                        payload['appId'] = appId;
                    }
                    if (typeof secret !== 'undefined') {
                        payload['secret'] = secret;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Platforms
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listPlatforms: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/platforms'.replace('{projectId}', projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} platformId
                 * @param {string} type
                 * @param {string} name
                 * @param {string} key
                 * @param {string} store
                 * @param {string} hostname
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createPlatform: (projectId, platformId, type, name, key, store, hostname) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof platformId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    if (typeof type === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "type"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/projects/{projectId}/platforms'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof platformId !== 'undefined') {
                        payload['platformId'] = platformId;
                    }
                    if (typeof type !== 'undefined') {
                        payload['type'] = type;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof key !== 'undefined') {
                        payload['key'] = key;
                    }
                    if (typeof store !== 'undefined') {
                        payload['store'] = store;
                    }
                    if (typeof hostname !== 'undefined') {
                        payload['hostname'] = hostname;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} platformId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getPlatform: (projectId, platformId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof platformId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    let path = '/projects/{projectId}/platforms/{platformId}'.replace('{projectId}', projectId).replace('{platformId}', platformId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} platformId
                 * @param {string} name
                 * @param {string} key
                 * @param {string} store
                 * @param {string} hostname
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updatePlatform: (projectId, platformId, name, key, store, hostname) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof platformId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/projects/{projectId}/platforms/{platformId}'.replace('{projectId}', projectId).replace('{platformId}', platformId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof key !== 'undefined') {
                        payload['key'] = key;
                    }
                    if (typeof store !== 'undefined') {
                        payload['store'] = store;
                    }
                    if (typeof hostname !== 'undefined') {
                        payload['hostname'] = hostname;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} platformId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deletePlatform: (projectId, platformId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof platformId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    let path = '/projects/{projectId}/platforms/{platformId}'.replace('{projectId}', projectId).replace('{platformId}', platformId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Tasks
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listTasks: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/tasks'.replace('{projectId}', projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Task
                 *
                 *
                 * @param {string} projectId
                 * @param {string} taskId
                 * @param {string} name
                 * @param {string} status
                 * @param {string} schedule
                 * @param {boolean} security
                 * @param {string} httpMethod
                 * @param {string} httpUrl
                 * @param {string[]} httpHeaders
                 * @param {string} httpUser
                 * @param {string} httpPass
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createTask: (projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders, httpUser, httpPass) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof taskId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof status === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    if (typeof schedule === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "schedule"');
                    }
                    if (typeof security === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    if (typeof httpMethod === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "httpMethod"');
                    }
                    if (typeof httpUrl === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "httpUrl"');
                    }
                    let path = '/projects/{projectId}/tasks'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof taskId !== 'undefined') {
                        payload['taskId'] = taskId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof status !== 'undefined') {
                        payload['status'] = status;
                    }
                    if (typeof schedule !== 'undefined') {
                        payload['schedule'] = schedule;
                    }
                    if (typeof security !== 'undefined') {
                        payload['security'] = security;
                    }
                    if (typeof httpMethod !== 'undefined') {
                        payload['httpMethod'] = httpMethod;
                    }
                    if (typeof httpUrl !== 'undefined') {
                        payload['httpUrl'] = httpUrl;
                    }
                    if (typeof httpHeaders !== 'undefined') {
                        payload['httpHeaders'] = httpHeaders;
                    }
                    if (typeof httpUser !== 'undefined') {
                        payload['httpUser'] = httpUser;
                    }
                    if (typeof httpPass !== 'undefined') {
                        payload['httpPass'] = httpPass;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Task
                 *
                 *
                 * @param {string} projectId
                 * @param {string} taskId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getTask: (projectId, taskId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof taskId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    let path = '/projects/{projectId}/tasks/{taskId}'.replace('{projectId}', projectId).replace('{taskId}', taskId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Task
                 *
                 *
                 * @param {string} projectId
                 * @param {string} taskId
                 * @param {string} name
                 * @param {string} status
                 * @param {string} schedule
                 * @param {boolean} security
                 * @param {string} httpMethod
                 * @param {string} httpUrl
                 * @param {string[]} httpHeaders
                 * @param {string} httpUser
                 * @param {string} httpPass
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateTask: (projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders, httpUser, httpPass) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof taskId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof status === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    if (typeof schedule === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "schedule"');
                    }
                    if (typeof security === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    if (typeof httpMethod === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "httpMethod"');
                    }
                    if (typeof httpUrl === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "httpUrl"');
                    }
                    let path = '/projects/{projectId}/tasks/{taskId}'.replace('{projectId}', projectId).replace('{taskId}', taskId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof status !== 'undefined') {
                        payload['status'] = status;
                    }
                    if (typeof schedule !== 'undefined') {
                        payload['schedule'] = schedule;
                    }
                    if (typeof security !== 'undefined') {
                        payload['security'] = security;
                    }
                    if (typeof httpMethod !== 'undefined') {
                        payload['httpMethod'] = httpMethod;
                    }
                    if (typeof httpUrl !== 'undefined') {
                        payload['httpUrl'] = httpUrl;
                    }
                    if (typeof httpHeaders !== 'undefined') {
                        payload['httpHeaders'] = httpHeaders;
                    }
                    if (typeof httpUser !== 'undefined') {
                        payload['httpUser'] = httpUser;
                    }
                    if (typeof httpPass !== 'undefined') {
                        payload['httpPass'] = httpPass;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Task
                 *
                 *
                 * @param {string} projectId
                 * @param {string} taskId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteTask: (projectId, taskId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof taskId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    let path = '/projects/{projectId}/tasks/{taskId}'.replace('{projectId}', projectId).replace('{taskId}', taskId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Project
                 *
                 *
                 * @param {string} projectId
                 * @param {string} range
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getUsage: (projectId, range) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/usage'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof range !== 'undefined') {
                        payload['range'] = range;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * List Webhooks
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listWebhooks: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/webhooks'.replace('{projectId}', projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} webhookId
                 * @param {string} name
                 * @param {string[]} events
                 * @param {string} url
                 * @param {boolean} security
                 * @param {string} httpUser
                 * @param {string} httpPass
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createWebhook: (projectId, webhookId, name, events, url, security, httpUser, httpPass) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof webhookId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof events === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "events"');
                    }
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    if (typeof security === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    let path = '/projects/{projectId}/webhooks'.replace('{projectId}', projectId);
                    let payload = {};
                    if (typeof webhookId !== 'undefined') {
                        payload['webhookId'] = webhookId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof events !== 'undefined') {
                        payload['events'] = events;
                    }
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    if (typeof security !== 'undefined') {
                        payload['security'] = security;
                    }
                    if (typeof httpUser !== 'undefined') {
                        payload['httpUser'] = httpUser;
                    }
                    if (typeof httpPass !== 'undefined') {
                        payload['httpPass'] = httpPass;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} webhookId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getWebhook: (projectId, webhookId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof webhookId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    let path = '/projects/{projectId}/webhooks/{webhookId}'.replace('{projectId}', projectId).replace('{webhookId}', webhookId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} webhookId
                 * @param {string} name
                 * @param {string[]} events
                 * @param {string} url
                 * @param {boolean} security
                 * @param {string} httpUser
                 * @param {string} httpPass
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateWebhook: (projectId, webhookId, name, events, url, security, httpUser, httpPass) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof webhookId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (typeof events === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "events"');
                    }
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    if (typeof security === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    let path = '/projects/{projectId}/webhooks/{webhookId}'.replace('{projectId}', projectId).replace('{webhookId}', webhookId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof events !== 'undefined') {
                        payload['events'] = events;
                    }
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    if (typeof security !== 'undefined') {
                        payload['security'] = security;
                    }
                    if (typeof httpUser !== 'undefined') {
                        payload['httpUser'] = httpUser;
                    }
                    if (typeof httpPass !== 'undefined') {
                        payload['httpPass'] = httpPass;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} webhookId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteWebhook: (projectId, webhookId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof projectId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (typeof webhookId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    let path = '/projects/{projectId}/webhooks/{webhookId}'.replace('{projectId}', projectId).replace('{webhookId}', webhookId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.storage = {
                /**
                 * List Files
                 *
                 * Get a list of all the user files. You can use the query params to filter
                 * your results. On admin mode, this endpoint will return a list of all of the
                 * project's files. [Learn more about different API modes](/docs/admin).
                 *
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                listFiles: (search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    let path = '/storage/files';
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create File
                 *
                 * Create a new file. The user who creates the file will automatically be
                 * assigned to read and write access unless he has passed custom values for
                 * read and write arguments.
                 *
                 * @param {File} file
                 * @param {string[]} read
                 * @param {string[]} write
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createFile: (file, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof file === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "file"');
                    }
                    let path = '/storage/files';
                    let payload = {};
                    if (typeof file !== 'undefined') {
                        payload['file'] = file;
                    }
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'multipart/form-data',
                    }, payload);
                }),
                /**
                 * Get File
                 *
                 * Get a file by its unique ID. This endpoint response returns a JSON object
                 * with the file metadata.
                 *
                 * @param {string} fileId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getFile: (fileId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof fileId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}'.replace('{fileId}', fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update File
                 *
                 * Update a file by its unique ID. Only users with write permissions have
                 * access to update this resource.
                 *
                 * @param {string} fileId
                 * @param {string[]} read
                 * @param {string[]} write
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateFile: (fileId, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof fileId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    if (typeof read === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (typeof write === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    let path = '/storage/files/{fileId}'.replace('{fileId}', fileId);
                    let payload = {};
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete File
                 *
                 * Delete a file by its unique ID. Only users with write permissions have
                 * access to delete this resource.
                 *
                 * @param {string} fileId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteFile: (fileId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof fileId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}'.replace('{fileId}', fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get File for Download
                 *
                 * Get a file content by its unique ID. The endpoint response return with a
                 * 'Content-Disposition: attachment' header that tells the browser to start
                 * downloading the file to user downloads directory.
                 *
                 * @param {string} fileId
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getFileDownload: (fileId) => {
                    if (typeof fileId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}/download'.replace('{fileId}', fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get File Preview
                 *
                 * Get a file preview image. Currently, this method supports preview for image
                 * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
                 * and spreadsheets, will return the file icon image. You can also pass query
                 * string arguments for cutting and resizing your preview image.
                 *
                 * @param {string} fileId
                 * @param {number} width
                 * @param {number} height
                 * @param {string} gravity
                 * @param {number} quality
                 * @param {number} borderWidth
                 * @param {string} borderColor
                 * @param {number} borderRadius
                 * @param {number} opacity
                 * @param {number} rotation
                 * @param {string} background
                 * @param {string} output
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getFilePreview: (fileId, width, height, gravity, quality, borderWidth, borderColor, borderRadius, opacity, rotation, background, output) => {
                    if (typeof fileId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}/preview'.replace('{fileId}', fileId);
                    let payload = {};
                    if (typeof width !== 'undefined') {
                        payload['width'] = width;
                    }
                    if (typeof height !== 'undefined') {
                        payload['height'] = height;
                    }
                    if (typeof gravity !== 'undefined') {
                        payload['gravity'] = gravity;
                    }
                    if (typeof quality !== 'undefined') {
                        payload['quality'] = quality;
                    }
                    if (typeof borderWidth !== 'undefined') {
                        payload['borderWidth'] = borderWidth;
                    }
                    if (typeof borderColor !== 'undefined') {
                        payload['borderColor'] = borderColor;
                    }
                    if (typeof borderRadius !== 'undefined') {
                        payload['borderRadius'] = borderRadius;
                    }
                    if (typeof opacity !== 'undefined') {
                        payload['opacity'] = opacity;
                    }
                    if (typeof rotation !== 'undefined') {
                        payload['rotation'] = rotation;
                    }
                    if (typeof background !== 'undefined') {
                        payload['background'] = background;
                    }
                    if (typeof output !== 'undefined') {
                        payload['output'] = output;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                },
                /**
                 * Get File for View
                 *
                 * Get a file content by its unique ID. This endpoint is similar to the
                 * download method but returns with no  'Content-Disposition: attachment'
                 * header.
                 *
                 * @param {string} fileId
                 * @throws {AppwriteException}
                 * @returns {URL}
                 */
                getFileView: (fileId) => {
                    if (typeof fileId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}/view'.replace('{fileId}', fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    for (const [key, value] of Object.entries(this.flatten(payload))) {
                        uri.searchParams.append(key, value);
                    }
                    return uri;
                }
            };
            this.teams = {
                /**
                 * List Teams
                 *
                 * Get a list of all the current user teams. You can use the query params to
                 * filter your results. On admin mode, this endpoint will return a list of all
                 * of the project's teams. [Learn more about different API
                 * modes](/docs/admin).
                 *
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                list: (search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    let path = '/teams';
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Team
                 *
                 * Create a new team. The user who creates the team will automatically be
                 * assigned as the owner of the team. The team owner can invite new members,
                 * who will be able add new owners and update or delete the team from your
                 * project.
                 *
                 * @param {string} teamId
                 * @param {string} name
                 * @param {string[]} roles
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                create: (teamId, name, roles) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/teams';
                    let payload = {};
                    if (typeof teamId !== 'undefined') {
                        payload['teamId'] = teamId;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof roles !== 'undefined') {
                        payload['roles'] = roles;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Team
                 *
                 * Get a team by its unique ID. All team members have read access for this
                 * resource.
                 *
                 * @param {string} teamId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: (teamId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/teams/{teamId}'.replace('{teamId}', teamId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Team
                 *
                 * Update a team by its unique ID. Only team owners have write access for this
                 * resource.
                 *
                 * @param {string} teamId
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                update: (teamId, name) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (typeof name === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/teams/{teamId}'.replace('{teamId}', teamId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Team
                 *
                 * Delete a team by its unique ID. Only team owners have write access for this
                 * resource.
                 *
                 * @param {string} teamId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                delete: (teamId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/teams/{teamId}'.replace('{teamId}', teamId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get Team Memberships
                 *
                 * Get a team members by the team unique ID. All team members have read access
                 * for this list of resources.
                 *
                 * @param {string} teamId
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getMemberships: (teamId, search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/teams/{teamId}/memberships'.replace('{teamId}', teamId);
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create Team Membership
                 *
                 * Use this endpoint to invite a new member to join your team. An email with a
                 * link to join the team will be sent to the new member email address if the
                 * member doesn't exist in the project it will be created automatically.
                 *
                 * Use the 'URL' parameter to redirect the user from the invitation email back
                 * to your app. When the user is redirected, use the [Update Team Membership
                 * Status](/docs/client/teams#teamsUpdateMembershipStatus) endpoint to allow
                 * the user to accept the invitation to the team.
                 *
                 * Please note that in order to avoid a [Redirect
                 * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
                 * the only valid redirect URL's are the once from domains you have set when
                 * added your platforms in the console interface.
                 *
                 * @param {string} teamId
                 * @param {string} membershipId
                 * @param {string} email
                 * @param {string[]} roles
                 * @param {string} url
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                createMembership: (teamId, membershipId, email, roles, url, name) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (typeof membershipId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "membershipId"');
                    }
                    if (typeof email === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (typeof roles === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "roles"');
                    }
                    if (typeof url === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/teams/{teamId}/memberships'.replace('{teamId}', teamId);
                    let payload = {};
                    if (typeof membershipId !== 'undefined') {
                        payload['membershipId'] = membershipId;
                    }
                    if (typeof email !== 'undefined') {
                        payload['email'] = email;
                    }
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof roles !== 'undefined') {
                        payload['roles'] = roles;
                    }
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Membership Roles
                 *
                 *
                 * @param {string} teamId
                 * @param {string} membershipId
                 * @param {string[]} roles
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateMembershipRoles: (teamId, membershipId, roles) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (typeof membershipId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "membershipId"');
                    }
                    if (typeof roles === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "roles"');
                    }
                    let path = '/teams/{teamId}/memberships/{membershipId}'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
                    let payload = {};
                    if (typeof roles !== 'undefined') {
                        payload['roles'] = roles;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete Team Membership
                 *
                 * This endpoint allows a user to leave a team or for a team owner to delete
                 * the membership of any other team member. You can also use this endpoint to
                 * delete a user membership even if it is not accepted.
                 *
                 * @param {string} teamId
                 * @param {string} membershipId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteMembership: (teamId, membershipId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (typeof membershipId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "membershipId"');
                    }
                    let path = '/teams/{teamId}/memberships/{membershipId}'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Team Membership Status
                 *
                 * Use this endpoint to allow a user to accept an invitation to join a team
                 * after being redirected back to your app from the invitation email recieved
                 * by the user.
                 *
                 * @param {string} teamId
                 * @param {string} membershipId
                 * @param {string} userId
                 * @param {string} secret
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateMembershipStatus: (teamId, membershipId, userId, secret) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof teamId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (typeof membershipId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "membershipId"');
                    }
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof secret === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "secret"');
                    }
                    let path = '/teams/{teamId}/memberships/{membershipId}/status'.replace('{teamId}', teamId).replace('{membershipId}', membershipId);
                    let payload = {};
                    if (typeof userId !== 'undefined') {
                        payload['userId'] = userId;
                    }
                    if (typeof secret !== 'undefined') {
                        payload['secret'] = secret;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
            this.users = {
                /**
                 * List Users
                 *
                 * Get a list of all the project's users. You can use the query params to
                 * filter your results.
                 *
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                list: (search, limit, offset, orderType) => __awaiter(this, void 0, void 0, function* () {
                    let path = '/users';
                    let payload = {};
                    if (typeof search !== 'undefined') {
                        payload['search'] = search;
                    }
                    if (typeof limit !== 'undefined') {
                        payload['limit'] = limit;
                    }
                    if (typeof offset !== 'undefined') {
                        payload['offset'] = offset;
                    }
                    if (typeof orderType !== 'undefined') {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Create User
                 *
                 * Create a new user.
                 *
                 * @param {string} userId
                 * @param {string} email
                 * @param {string} password
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                create: (userId, email, password, name) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof email === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (typeof password === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/users';
                    let payload = {};
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
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get User
                 *
                 * Get a user by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                get: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}'.replace('{userId}', userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete User
                 *
                 * Delete a user by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                delete: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}'.replace('{userId}', userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get User Logs
                 *
                 * Get a user activity logs list by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getLogs: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/logs'.replace('{userId}', userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get User Preferences
                 *
                 * Get the user preferences by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getPrefs: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/prefs'.replace('{userId}', userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update User Preferences
                 *
                 * Update the user preferences by its unique ID. You can pass only the
                 * specific settings you wish to update.
                 *
                 * @param {string} userId
                 * @param {object} prefs
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updatePrefs: (userId, prefs) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof prefs === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "prefs"');
                    }
                    let path = '/users/{userId}/prefs'.replace('{userId}', userId);
                    let payload = {};
                    if (typeof prefs !== 'undefined') {
                        payload['prefs'] = prefs;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Get User Sessions
                 *
                 * Get the user sessions list by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                getSessions: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/sessions'.replace('{userId}', userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete User Sessions
                 *
                 * Delete all user's sessions by using the user's unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteSessions: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/sessions'.replace('{userId}', userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Delete User Session
                 *
                 * Delete a user sessions by its unique ID.
                 *
                 * @param {string} userId
                 * @param {string} sessionId
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                deleteSession: (userId, sessionId) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof sessionId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "sessionId"');
                    }
                    let path = '/users/{userId}/sessions/{sessionId}'.replace('{userId}', userId).replace('{sessionId}', sessionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update User Status
                 *
                 * Update the user status by its unique ID.
                 *
                 * @param {string} userId
                 * @param {boolean} status
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateStatus: (userId, status) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof status === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    let path = '/users/{userId}/status'.replace('{userId}', userId);
                    let payload = {};
                    if (typeof status !== 'undefined') {
                        payload['status'] = status;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                }),
                /**
                 * Update Email Verification
                 *
                 * Update the user email verification status by its unique ID.
                 *
                 * @param {string} userId
                 * @param {boolean} emailVerification
                 * @throws {AppwriteException}
                 * @returns {Promise}
                 */
                updateVerification: (userId, emailVerification) => __awaiter(this, void 0, void 0, function* () {
                    if (typeof userId === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (typeof emailVerification === 'undefined') {
                        throw new AppwriteException('Missing required parameter: "emailVerification"');
                    }
                    let path = '/users/{userId}/verification'.replace('{userId}', userId);
                    let payload = {};
                    if (typeof emailVerification !== 'undefined') {
                        payload['emailVerification'] = emailVerification;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, {
                        'content-type': 'application/json',
                    }, payload);
                })
            };
        }
        /**
         * Set Endpoint
         *
         * Your project endpoint
         *
         * @param {string} endpoint
         *
         * @returns {this}
         */
        setEndpoint(endpoint) {
            this.config.endpoint = endpoint;
            return this;
        }
        /**
         * Set Project
         *
         * Your project ID
         *
         * @param value string
         *
         * @return {this}
         */
        setProject(value) {
            this.headers['X-Appwrite-Project'] = value;
            this.config.project = value;
            return this;
        }
        /**
         * Set Key
         *
         * Your secret API key
         *
         * @param value string
         *
         * @return {this}
         */
        setKey(value) {
            this.headers['X-Appwrite-Key'] = value;
            this.config.key = value;
            return this;
        }
        /**
         * Set JWT
         *
         * Your secret JSON Web Token
         *
         * @param value string
         *
         * @return {this}
         */
        setJWT(value) {
            this.headers['X-Appwrite-JWT'] = value;
            this.config.jwt = value;
            return this;
        }
        /**
         * Set Locale
         *
         * @param value string
         *
         * @return {this}
         */
        setLocale(value) {
            this.headers['X-Appwrite-Locale'] = value;
            this.config.locale = value;
            return this;
        }
        /**
         * Set Mode
         *
         * @param value string
         *
         * @return {this}
         */
        setMode(value) {
            this.headers['X-Appwrite-Mode'] = value;
            this.config.mode = value;
            return this;
        }
        call(method, url, headers = {}, params = {}) {
            var _a, _b;
            return __awaiter(this, void 0, void 0, function* () {
                method = method.toUpperCase();
                headers = Object.assign(Object.assign({}, headers), this.headers);
                let options = {
                    method,
                    headers,
                    credentials: 'include'
                };
                if (typeof window !== 'undefined' && window.localStorage) {
                    headers['X-Fallback-Cookies'] = (_a = window.localStorage.getItem('cookieFallback')) !== null && _a !== void 0 ? _a : "";
                }
                if (method === 'GET') {
                    for (const [key, value] of Object.entries(this.flatten(params))) {
                        url.searchParams.append(key, value);
                    }
                }
                else {
                    switch (headers['content-type']) {
                        case 'application/json':
                            options.body = JSON.stringify(params);
                            break;
                        case 'multipart/form-data':
                            let formData = new FormData();
                            for (const key in params) {
                                if (Array.isArray(params[key])) {
                                    formData.append(key + '[]', params[key].join(','));
                                }
                                else {
                                    formData.append(key, params[key]);
                                }
                            }
                            options.body = formData;
                            delete headers['content-type'];
                            break;
                    }
                }
                try {
                    let data = null;
                    const response = yield crossFetch.fetch(url.toString(), options);
                    if ((_b = response.headers.get("content-type")) === null || _b === void 0 ? void 0 : _b.includes("application/json")) {
                        data = yield response.json();
                    }
                    else {
                        data = {
                            message: yield response.text()
                        };
                    }
                    if (400 <= response.status) {
                        throw new AppwriteException(data === null || data === void 0 ? void 0 : data.message, response.status, data);
                    }
                    const cookieFallback = response.headers.get('X-Fallback-Cookies');
                    if (typeof window !== 'undefined' && window.localStorage && cookieFallback) {
                        window.console.warn('Appwrite is using localStorage for session management. Increase your security by adding a custom domain as your API endpoint.');
                        window.localStorage.setItem('cookieFallback', cookieFallback);
                    }
                    return data;
                }
                catch (e) {
                    throw new AppwriteException(e.message);
                }
            });
        }
        flatten(data, prefix = '') {
            let output = {};
            for (const key in data) {
                let value = data[key];
                let finalKey = prefix ? `${prefix}[${key}]` : key;
                if (Array.isArray(value)) {
                    output = Object.assign(output, this.flatten(value, finalKey));
                }
                else {
                    output[finalKey] = value;
                }
            }
            return output;
        }
    }

    exports.Appwrite = Appwrite;

    Object.defineProperty(exports, '__esModule', { value: true });

}(this.window = this.window || {}, null, window));
