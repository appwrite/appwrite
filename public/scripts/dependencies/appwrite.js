(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
    typeof define === 'function' && define.amd ? define(['exports'], factory) :
    (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global.Appwrite = {}));
}(this, (function (exports) { 'use strict';

    var __awaiter = (window && window.__awaiter) || function (thisArg, _arguments, P, generator) {
        function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
        return new (P || (P = Promise))(function (resolve, reject) {
            function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
            function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
            function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
            step((generator = generator.apply(thisArg, _arguments || [])).next());
        });
    };
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
                locale: '',
                mode: '',
            };
            this.headers = new Headers({
                'x-sdk-version': 'appwrite:web:1.0.0',
            });
            this.account = {
                /**
                 * Get Account
                 *
                 * Get currently logged in user data as JSON object.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                get: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                create: (email, password, name = '') => __awaiter(this, void 0, void 0, function* () {
                    if (email === undefined) {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (password === undefined) {
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                delete: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Account Email
                 *
                 * Update currently logged in user account email address. After changing user
                 * address, user confirmation status is being reset and a new confirmation
                 * mail is sent. For security measures, user password is required to complete
                 * this request.
                 *
                 * @param {string} email
                 * @param {string} password
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateEmail: (email, password) => __awaiter(this, void 0, void 0, function* () {
                    if (email === undefined) {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (password === undefined) {
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
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Account Logs
                 *
                 * Get currently logged in user list of latest security activity logs. Each
                 * log returns user IP address, location and date and time of log.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getLogs: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/logs';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Account Name
                 *
                 * Update currently logged in user account name.
                 *
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateName: (name) => __awaiter(this, void 0, void 0, function* () {
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/account/name';
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Account Password
                 *
                 * Update currently logged in user password. For validation, user is required
                 * to pass the password twice.
                 *
                 * @param {string} password
                 * @param {string} oldPassword
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updatePassword: (password, oldPassword) => __awaiter(this, void 0, void 0, function* () {
                    if (password === undefined) {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    if (oldPassword === undefined) {
                        throw new AppwriteException('Missing required parameter: "oldPassword"');
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
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Account Preferences
                 *
                 * Get currently logged in user preferences as a key-value object.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getPrefs: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/prefs';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Account Preferences
                 *
                 * Update currently logged in user account preferences. You can pass only the
                 * specific settings you wish to update.
                 *
                 * @param {object} prefs
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updatePrefs: (prefs) => __awaiter(this, void 0, void 0, function* () {
                    if (prefs === undefined) {
                        throw new AppwriteException('Missing required parameter: "prefs"');
                    }
                    let path = '/account/prefs';
                    let payload = {};
                    if (typeof prefs !== 'undefined') {
                        payload['prefs'] = prefs;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * complete the process.
                 *
                 * @param {string} email
                 * @param {string} url
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createRecovery: (email, url) => __awaiter(this, void 0, void 0, function* () {
                    if (email === undefined) {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (url === undefined) {
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateRecovery: (userId, secret, password, passwordAgain) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (secret === undefined) {
                        throw new AppwriteException('Missing required parameter: "secret"');
                    }
                    if (password === undefined) {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    if (passwordAgain === undefined) {
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
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Account Sessions
                 *
                 * Get currently logged in user list of active sessions across different
                 * devices.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getSessions: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/sessions';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                createSession: (email, password) => __awaiter(this, void 0, void 0, function* () {
                    if (email === undefined) {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (password === undefined) {
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete All Account Sessions
                 *
                 * Delete all sessions from the user account and remove any sessions cookies
                 * from the end client.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteSessions: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/account/sessions';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Account Session with OAuth2
                 *
                 * Allow the user to login to their account using the OAuth2 provider of their
                 * choice. Each OAuth2 provider should be enabled from the Appwrite console
                 * first. Use the success and failure arguments to provide a redirect URL's
                 * back to your app when login is completed.
                 *
                 * @param {string} provider
                 * @param {string} success
                 * @param {string} failure
                 * @param {string[]} scopes
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createOAuth2Session: (provider, success = 'https://appwrite.io/auth/oauth2/success', failure = 'https://appwrite.io/auth/oauth2/failure', scopes = []) => {
                    if (provider === undefined) {
                        throw new AppwriteException('Missing required parameter: "provider"');
                    }
                    let path = '/account/sessions/oauth2/{provider}'.replace(new RegExp('{provider}', 'g'), provider);
                    let payload = {};
                    if (success) {
                        payload['success'] = success;
                    }
                    if (failure) {
                        payload['failure'] = failure;
                    }
                    if (scopes) {
                        payload['scopes'] = scopes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
                    window.location.href = uri.toString();
                },
                /**
                 * Delete Account Session
                 *
                 * Use this endpoint to log out the currently logged in user from all their
                 * account sessions across all of their different devices. When using the
                 * option id argument, only the session unique ID provider will be deleted.
                 *
                 * @param {string} sessionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteSession: (sessionId) => __awaiter(this, void 0, void 0, function* () {
                    if (sessionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "sessionId"');
                    }
                    let path = '/account/sessions/{sessionId}'.replace(new RegExp('{sessionId}', 'g'), sessionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * process](/docs/client/account#accountUpdateVerification).
                 *
                 * Please note that in order to avoid a [Redirect
                 * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md),
                 * the only valid redirect URLs are the ones from domains you have set when
                 * adding your platforms in the console interface.
                 *
                 *
                 * @param {string} url
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createVerification: (url) => __awaiter(this, void 0, void 0, function* () {
                    if (url === undefined) {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/account/verification';
                    let payload = {};
                    if (typeof url !== 'undefined') {
                        payload['url'] = url;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateVerification: (userId, secret) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (secret === undefined) {
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
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {string}
                 */
                getBrowser: (code, width = 100, height = 100, quality = 100) => {
                    if (code === undefined) {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/avatars/browsers/{code}'.replace(new RegExp('{code}', 'g'), code);
                    let payload = {};
                    if (width) {
                        payload['width'] = width;
                    }
                    if (height) {
                        payload['height'] = height;
                    }
                    if (quality) {
                        payload['quality'] = quality;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getCreditCard: (code, width = 100, height = 100, quality = 100) => {
                    if (code === undefined) {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/avatars/credit-cards/{code}'.replace(new RegExp('{code}', 'g'), code);
                    let payload = {};
                    if (width) {
                        payload['width'] = width;
                    }
                    if (height) {
                        payload['height'] = height;
                    }
                    if (quality) {
                        payload['quality'] = quality;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getFavicon: (url) => {
                    if (url === undefined) {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/avatars/favicon';
                    let payload = {};
                    if (url) {
                        payload['url'] = url;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getFlag: (code, width = 100, height = 100, quality = 100) => {
                    if (code === undefined) {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/avatars/flags/{code}'.replace(new RegExp('{code}', 'g'), code);
                    let payload = {};
                    if (width) {
                        payload['width'] = width;
                    }
                    if (height) {
                        payload['height'] = height;
                    }
                    if (quality) {
                        payload['quality'] = quality;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getImage: (url, width = 400, height = 400) => {
                    if (url === undefined) {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/avatars/image';
                    let payload = {};
                    if (url) {
                        payload['url'] = url;
                    }
                    if (width) {
                        payload['width'] = width;
                    }
                    if (height) {
                        payload['height'] = height;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getInitials: (name = '', width = 500, height = 500, color = '', background = '') => {
                    let path = '/avatars/initials';
                    let payload = {};
                    if (name) {
                        payload['name'] = name;
                    }
                    if (width) {
                        payload['width'] = width;
                    }
                    if (height) {
                        payload['height'] = height;
                    }
                    if (color) {
                        payload['color'] = color;
                    }
                    if (background) {
                        payload['background'] = background;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getQR: (text, size = 400, margin = 1, download = false) => {
                    if (text === undefined) {
                        throw new AppwriteException('Missing required parameter: "text"');
                    }
                    let path = '/avatars/qr';
                    let payload = {};
                    if (text) {
                        payload['text'] = text;
                    }
                    if (size) {
                        payload['size'] = size;
                    }
                    if (margin) {
                        payload['margin'] = margin;
                    }
                    if (download) {
                        payload['download'] = download;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {Promise}
                 */
                listCollections: (search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    let path = '/database/collections';
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Collection
                 *
                 * Create a new Collection.
                 *
                 * @param {string} name
                 * @param {string[]} read
                 * @param {string[]} write
                 * @param {string[]} rules
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createCollection: (name, read, write, rules) => __awaiter(this, void 0, void 0, function* () {
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (read === undefined) {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (write === undefined) {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    if (rules === undefined) {
                        throw new AppwriteException('Missing required parameter: "rules"');
                    }
                    let path = '/database/collections';
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
                    if (typeof rules !== 'undefined') {
                        payload['rules'] = rules;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Collection
                 *
                 * Get a collection by its unique ID. This endpoint response returns a JSON
                 * object with the collection metadata.
                 *
                 * @param {string} collectionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getCollection: (collectionId) => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Collection
                 *
                 * Update a collection by its unique ID.
                 *
                 * @param {string} collectionId
                 * @param {string} name
                 * @param {string[]} read
                 * @param {string[]} write
                 * @param {string[]} rules
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateCollection: (collectionId, name, read, write, rules = []) => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (read === undefined) {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (write === undefined) {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
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
                    if (typeof rules !== 'undefined') {
                        payload['rules'] = rules;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Collection
                 *
                 * Delete a collection by its unique ID. Only users with write permissions
                 * have access to delete this resource.
                 *
                 * @param {string} collectionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteCollection: (collectionId) => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @param {string[]} filters
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderField
                 * @param {string} orderType
                 * @param {string} orderCast
                 * @param {string} search
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listDocuments: (collectionId, filters = [], limit = 25, offset = 0, orderField = '', orderType = 'ASC', orderCast = 'string', search = '') => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);
                    let payload = {};
                    if (filters) {
                        payload['filters'] = filters;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderField) {
                        payload['orderField'] = orderField;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    if (orderCast) {
                        payload['orderCast'] = orderCast;
                    }
                    if (search) {
                        payload['search'] = search;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @param {object} data
                 * @param {string[]} read
                 * @param {string[]} write
                 * @param {string} parentDocument
                 * @param {string} parentProperty
                 * @param {string} parentPropertyType
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createDocument: (collectionId, data, read, write, parentDocument = '', parentProperty = '', parentPropertyType = 'assign') => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (data === undefined) {
                        throw new AppwriteException('Missing required parameter: "data"');
                    }
                    if (read === undefined) {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (write === undefined) {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);
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
                    if (typeof parentDocument !== 'undefined') {
                        payload['parentDocument'] = parentDocument;
                    }
                    if (typeof parentProperty !== 'undefined') {
                        payload['parentProperty'] = parentProperty;
                    }
                    if (typeof parentPropertyType !== 'undefined') {
                        payload['parentPropertyType'] = parentPropertyType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                getDocument: (collectionId, documentId) => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (documentId === undefined) {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @param {string[]} read
                 * @param {string[]} write
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateDocument: (collectionId, documentId, data, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (documentId === undefined) {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    if (data === undefined) {
                        throw new AppwriteException('Missing required parameter: "data"');
                    }
                    if (read === undefined) {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (write === undefined) {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
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
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                deleteDocument: (collectionId, documentId) => __awaiter(this, void 0, void 0, function* () {
                    if (collectionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "collectionId"');
                    }
                    if (documentId === undefined) {
                        throw new AppwriteException('Missing required parameter: "documentId"');
                    }
                    let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                list: (search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    let path = '/functions';
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Function
                 *
                 * Create a new function. You can pass a list of
                 * [permissions](/docs/permissions) to allow different project users or team
                 * with access to execute the function using the client API.
                 *
                 * @param {string} name
                 * @param {string[]} execute
                 * @param {string} env
                 * @param {object} vars
                 * @param {string[]} events
                 * @param {string} schedule
                 * @param {number} timeout
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                create: (name, execute, env, vars = {}, events = [], schedule = '', timeout = 15) => __awaiter(this, void 0, void 0, function* () {
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (execute === undefined) {
                        throw new AppwriteException('Missing required parameter: "execute"');
                    }
                    if (env === undefined) {
                        throw new AppwriteException('Missing required parameter: "env"');
                    }
                    let path = '/functions';
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof execute !== 'undefined') {
                        payload['execute'] = execute;
                    }
                    if (typeof env !== 'undefined') {
                        payload['env'] = env;
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Function
                 *
                 * Get a function by its unique ID.
                 *
                 * @param {string} functionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                get: (functionId) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                update: (functionId, name, execute, vars = {}, events = [], schedule = '', timeout = 15) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (execute === undefined) {
                        throw new AppwriteException('Missing required parameter: "execute"');
                    }
                    let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
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
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Function
                 *
                 * Delete a function by its unique ID.
                 *
                 * @param {string} functionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                delete: (functionId) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Executions
                 *
                 * Get a list of all the current user function execution logs. You can use the
                 * query params to filter your results. On admin mode, this endpoint will
                 * return a list of all of the project's teams. [Learn more about different
                 * API modes](/docs/admin).
                 *
                 * @param {string} functionId
                 * @param {string} search
                 * @param {number} limit
                 * @param {number} offset
                 * @param {string} orderType
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listExecutions: (functionId, search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createExecution: (functionId) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Execution
                 *
                 * Get a function execution log by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} executionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getExecution: (functionId, executionId) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (executionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "executionId"');
                    }
                    let path = '/functions/{functionId}/executions/{executionId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{executionId}', 'g'), executionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateTag: (functionId, tag) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (tag === undefined) {
                        throw new AppwriteException('Missing required parameter: "tag"');
                    }
                    let path = '/functions/{functionId}/tag'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    if (typeof tag !== 'undefined') {
                        payload['tag'] = tag;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                listTags: (functionId, search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @param {string} functionId
                 * @param {string} command
                 * @param {File} code
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createTag: (functionId, command, code) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (command === undefined) {
                        throw new AppwriteException('Missing required parameter: "command"');
                    }
                    if (code === undefined) {
                        throw new AppwriteException('Missing required parameter: "code"');
                    }
                    let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    if (typeof command !== 'undefined') {
                        payload['command'] = command;
                    }
                    if (typeof code !== 'undefined') {
                        payload['code'] = code;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'multipart/form-data',
                    }), payload);
                }),
                /**
                 * Get Tag
                 *
                 * Get a code tag by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} tagId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getTag: (functionId, tagId) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (tagId === undefined) {
                        throw new AppwriteException('Missing required parameter: "tagId"');
                    }
                    let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Tag
                 *
                 * Delete a code tag by its unique ID.
                 *
                 * @param {string} functionId
                 * @param {string} tagId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteTag: (functionId, tagId) => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    if (tagId === undefined) {
                        throw new AppwriteException('Missing required parameter: "tagId"');
                    }
                    let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Function Usage
                 *
                 *
                 * @param {string} functionId
                 * @param {string} range
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getUsage: (functionId, range = '30d') => __awaiter(this, void 0, void 0, function* () {
                    if (functionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "functionId"');
                    }
                    let path = '/functions/{functionId}/usage'.replace(new RegExp('{functionId}', 'g'), functionId);
                    let payload = {};
                    if (range) {
                        payload['range'] = range;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                })
            };
            this.health = {
                /**
                 * Get HTTP
                 *
                 * Check the Appwrite HTTP server is up and responsive.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                get: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Anti virus
                 *
                 * Check the Appwrite Anti Virus server is up and connection is successful.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getAntiVirus: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/anti-virus';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Cache
                 *
                 * Check the Appwrite in-memory cache server is up and connection is
                 * successful.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getCache: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/cache';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get DB
                 *
                 * Check the Appwrite database server is up and connection is successful.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getDB: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/db';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Certificate Queue
                 *
                 * Get the number of certificates that are waiting to be issued against
                 * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
                 * server.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getQueueCertificates: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/certificates';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Functions Queue
                 *
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getQueueFunctions: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/functions';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Logs Queue
                 *
                 * Get the number of logs that are waiting to be processed in the Appwrite
                 * internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getQueueLogs: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/logs';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Tasks Queue
                 *
                 * Get the number of tasks that are waiting to be processed in the Appwrite
                 * internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getQueueTasks: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/tasks';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Usage Queue
                 *
                 * Get the number of usage stats that are waiting to be processed in the
                 * Appwrite internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getQueueUsage: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/usage';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Webhooks Queue
                 *
                 * Get the number of webhooks that are waiting to be processed in the Appwrite
                 * internal queue server.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getQueueWebhooks: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/queue/webhooks';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Local Storage
                 *
                 * Check the Appwrite local storage device is up and connection is successful.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getStorageLocal: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/storage/local';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                getTime: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/health/time';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                get: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Continents
                 *
                 * List of all continents. You can use the locale header to get the data in a
                 * supported language.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getContinents: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/continents';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Countries
                 *
                 * List of all countries. You can use the locale header to get the data in a
                 * supported language.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getCountries: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/countries';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List EU Countries
                 *
                 * List of all countries that are currently members of the EU. You can use the
                 * locale header to get the data in a supported language.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getCountriesEU: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/countries/eu';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Countries Phone Codes
                 *
                 * List of all countries phone codes. You can use the locale header to get the
                 * data in a supported language.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getCountriesPhones: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/countries/phones';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Currencies
                 *
                 * List of all currencies, including currency symbol, name, plural, and
                 * decimal digits for all major and minor currencies. You can use the locale
                 * header to get the data in a supported language.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getCurrencies: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/currencies';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Languages
                 *
                 * List of all languages classified by ISO 639-1 including 2-letter code, name
                 * in English, and name in the respective language.
                 *
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getLanguages: () => __awaiter(this, void 0, void 0, function* () {
                    let path = '/locale/languages';
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                list: (search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    let path = '/projects';
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Project
                 *
                 *
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
                 * @return {Promise}
                 */
                create: (name, teamId, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') => __awaiter(this, void 0, void 0, function* () {
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/projects';
                    let payload = {};
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Project
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                get: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                update: (projectId, name, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);
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
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Project
                 *
                 *
                 * @param {string} projectId
                 * @param {string} password
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                delete: (projectId, password) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (password === undefined) {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    if (typeof password !== 'undefined') {
                        payload['password'] = password;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Domains
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listDomains: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/domains'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Domain
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domain
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createDomain: (projectId, domain) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (domain === undefined) {
                        throw new AppwriteException('Missing required parameter: "domain"');
                    }
                    let path = '/projects/{projectId}/domains'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    if (typeof domain !== 'undefined') {
                        payload['domain'] = domain;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Domain
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getDomain: (projectId, domainId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (domainId === undefined) {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    let path = '/projects/{projectId}/domains/{domainId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{domainId}', 'g'), domainId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Domain
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteDomain: (projectId, domainId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (domainId === undefined) {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    let path = '/projects/{projectId}/domains/{domainId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{domainId}', 'g'), domainId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Domain Verification Status
                 *
                 *
                 * @param {string} projectId
                 * @param {string} domainId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateDomainVerification: (projectId, domainId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (domainId === undefined) {
                        throw new AppwriteException('Missing required parameter: "domainId"');
                    }
                    let path = '/projects/{projectId}/domains/{domainId}/verification'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{domainId}', 'g'), domainId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Keys
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listKeys: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} name
                 * @param {string[]} scopes
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createKey: (projectId, name, scopes) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (scopes === undefined) {
                        throw new AppwriteException('Missing required parameter: "scopes"');
                    }
                    let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof scopes !== 'undefined') {
                        payload['scopes'] = scopes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} keyId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getKey: (projectId, keyId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (keyId === undefined) {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateKey: (projectId, keyId, name, scopes) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (keyId === undefined) {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (scopes === undefined) {
                        throw new AppwriteException('Missing required parameter: "scopes"');
                    }
                    let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof scopes !== 'undefined') {
                        payload['scopes'] = scopes;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Key
                 *
                 *
                 * @param {string} projectId
                 * @param {string} keyId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteKey: (projectId, keyId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (keyId === undefined) {
                        throw new AppwriteException('Missing required parameter: "keyId"');
                    }
                    let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateOAuth2: (projectId, provider, appId = '', secret = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (provider === undefined) {
                        throw new AppwriteException('Missing required parameter: "provider"');
                    }
                    let path = '/projects/{projectId}/oauth2'.replace(new RegExp('{projectId}', 'g'), projectId);
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
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Platforms
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listPlatforms: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} type
                 * @param {string} name
                 * @param {string} key
                 * @param {string} store
                 * @param {string} hostname
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createPlatform: (projectId, type, name, key = '', store = '', hostname = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (type === undefined) {
                        throw new AppwriteException('Missing required parameter: "type"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} platformId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getPlatform: (projectId, platformId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (platformId === undefined) {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updatePlatform: (projectId, platformId, name, key = '', store = '', hostname = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (platformId === undefined) {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);
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
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Platform
                 *
                 *
                 * @param {string} projectId
                 * @param {string} platformId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deletePlatform: (projectId, platformId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (platformId === undefined) {
                        throw new AppwriteException('Missing required parameter: "platformId"');
                    }
                    let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Tasks
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listTasks: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Task
                 *
                 *
                 * @param {string} projectId
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
                 * @return {Promise}
                 */
                createTask: (projectId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = [], httpUser = '', httpPass = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (status === undefined) {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    if (schedule === undefined) {
                        throw new AppwriteException('Missing required parameter: "schedule"');
                    }
                    if (security === undefined) {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    if (httpMethod === undefined) {
                        throw new AppwriteException('Missing required parameter: "httpMethod"');
                    }
                    if (httpUrl === undefined) {
                        throw new AppwriteException('Missing required parameter: "httpUrl"');
                    }
                    let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Task
                 *
                 *
                 * @param {string} projectId
                 * @param {string} taskId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getTask: (projectId, taskId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (taskId === undefined) {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateTask: (projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = [], httpUser = '', httpPass = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (taskId === undefined) {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (status === undefined) {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    if (schedule === undefined) {
                        throw new AppwriteException('Missing required parameter: "schedule"');
                    }
                    if (security === undefined) {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    if (httpMethod === undefined) {
                        throw new AppwriteException('Missing required parameter: "httpMethod"');
                    }
                    if (httpUrl === undefined) {
                        throw new AppwriteException('Missing required parameter: "httpUrl"');
                    }
                    let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);
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
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Task
                 *
                 *
                 * @param {string} projectId
                 * @param {string} taskId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteTask: (projectId, taskId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (taskId === undefined) {
                        throw new AppwriteException('Missing required parameter: "taskId"');
                    }
                    let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Project
                 *
                 *
                 * @param {string} projectId
                 * @param {string} range
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getUsage: (projectId, range = '30d') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/usage'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    if (range) {
                        payload['range'] = range;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * List Webhooks
                 *
                 *
                 * @param {string} projectId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                listWebhooks: (projectId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} name
                 * @param {string[]} events
                 * @param {string} url
                 * @param {boolean} security
                 * @param {string} httpUser
                 * @param {string} httpPass
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createWebhook: (projectId, name, events, url, security, httpUser = '', httpPass = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (events === undefined) {
                        throw new AppwriteException('Missing required parameter: "events"');
                    }
                    if (url === undefined) {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    if (security === undefined) {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} webhookId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getWebhook: (projectId, webhookId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (webhookId === undefined) {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateWebhook: (projectId, webhookId, name, events, url, security, httpUser = '', httpPass = '') => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (webhookId === undefined) {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    if (events === undefined) {
                        throw new AppwriteException('Missing required parameter: "events"');
                    }
                    if (url === undefined) {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    if (security === undefined) {
                        throw new AppwriteException('Missing required parameter: "security"');
                    }
                    let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);
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
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Webhook
                 *
                 *
                 * @param {string} projectId
                 * @param {string} webhookId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteWebhook: (projectId, webhookId) => __awaiter(this, void 0, void 0, function* () {
                    if (projectId === undefined) {
                        throw new AppwriteException('Missing required parameter: "projectId"');
                    }
                    if (webhookId === undefined) {
                        throw new AppwriteException('Missing required parameter: "webhookId"');
                    }
                    let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                listFiles: (search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    let path = '/storage/files';
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                createFile: (file, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (file === undefined) {
                        throw new AppwriteException('Missing required parameter: "file"');
                    }
                    if (read === undefined) {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (write === undefined) {
                        throw new AppwriteException('Missing required parameter: "write"');
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'multipart/form-data',
                    }), payload);
                }),
                /**
                 * Get File
                 *
                 * Get a file by its unique ID. This endpoint response returns a JSON object
                 * with the file metadata.
                 *
                 * @param {string} fileId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getFile: (fileId) => __awaiter(this, void 0, void 0, function* () {
                    if (fileId === undefined) {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updateFile: (fileId, read, write) => __awaiter(this, void 0, void 0, function* () {
                    if (fileId === undefined) {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    if (read === undefined) {
                        throw new AppwriteException('Missing required parameter: "read"');
                    }
                    if (write === undefined) {
                        throw new AppwriteException('Missing required parameter: "write"');
                    }
                    let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
                    let payload = {};
                    if (typeof read !== 'undefined') {
                        payload['read'] = read;
                    }
                    if (typeof write !== 'undefined') {
                        payload['write'] = write;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete File
                 *
                 * Delete a file by its unique ID. Only users with write permissions have
                 * access to delete this resource.
                 *
                 * @param {string} fileId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteFile: (fileId) => __awaiter(this, void 0, void 0, function* () {
                    if (fileId === undefined) {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {string}
                 */
                getFileDownload: (fileId) => {
                    if (fileId === undefined) {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}/download'.replace(new RegExp('{fileId}', 'g'), fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @param {number} quality
                 * @param {string} background
                 * @param {string} output
                 * @throws {AppwriteException}
                 * @return {string}
                 */
                getFilePreview: (fileId, width = 0, height = 0, quality = 100, background = '', output = '') => {
                    if (fileId === undefined) {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}/preview'.replace(new RegExp('{fileId}', 'g'), fileId);
                    let payload = {};
                    if (width) {
                        payload['width'] = width;
                    }
                    if (height) {
                        payload['height'] = height;
                    }
                    if (quality) {
                        payload['quality'] = quality;
                    }
                    if (background) {
                        payload['background'] = background;
                    }
                    if (output) {
                        payload['output'] = output;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {string}
                 */
                getFileView: (fileId) => {
                    if (fileId === undefined) {
                        throw new AppwriteException('Missing required parameter: "fileId"');
                    }
                    let path = '/storage/files/{fileId}/view'.replace(new RegExp('{fileId}', 'g'), fileId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    payload['project'] = this.config.project;
                    payload['key'] = this.config.key;
                    const query = new URLSearchParams(payload);
                    uri.search = query.toString();
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
                 * @return {Promise}
                 */
                list: (search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    let path = '/teams';
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create Team
                 *
                 * Create a new team. The user who creates the team will automatically be
                 * assigned as the owner of the team. The team owner can invite new members,
                 * who will be able add new owners and update or delete the team from your
                 * project.
                 *
                 * @param {string} name
                 * @param {string[]} roles
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                create: (name, roles = ["owner"]) => __awaiter(this, void 0, void 0, function* () {
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/teams';
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    if (typeof roles !== 'undefined') {
                        payload['roles'] = roles;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get Team
                 *
                 * Get a team by its unique ID. All team members have read access for this
                 * resource.
                 *
                 * @param {string} teamId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                get: (teamId) => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                update: (teamId, name) => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (name === undefined) {
                        throw new AppwriteException('Missing required parameter: "name"');
                    }
                    let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
                    let payload = {};
                    if (typeof name !== 'undefined') {
                        payload['name'] = name;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('put', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Team
                 *
                 * Delete a team by its unique ID. Only team owners have write access for this
                 * resource.
                 *
                 * @param {string} teamId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                delete: (teamId) => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                getMemberships: (teamId, search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @param {string} email
                 * @param {string[]} roles
                 * @param {string} url
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                createMembership: (teamId, email, roles, url, name = '') => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (email === undefined) {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (roles === undefined) {
                        throw new AppwriteException('Missing required parameter: "roles"');
                    }
                    if (url === undefined) {
                        throw new AppwriteException('Missing required parameter: "url"');
                    }
                    let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);
                    let payload = {};
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete Team Membership
                 *
                 * This endpoint allows a user to leave a team or for a team owner to delete
                 * the membership of any other team member. You can also use this endpoint to
                 * delete a user membership even if it is not accepted.
                 *
                 * @param {string} teamId
                 * @param {string} inviteId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteMembership: (teamId, inviteId) => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (inviteId === undefined) {
                        throw new AppwriteException('Missing required parameter: "inviteId"');
                    }
                    let path = '/teams/{teamId}/memberships/{inviteId}'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update Team Membership Status
                 *
                 * Use this endpoint to allow a user to accept an invitation to join a team
                 * after being redirected back to your app from the invitation email recieved
                 * by the user.
                 *
                 * @param {string} teamId
                 * @param {string} inviteId
                 * @param {string} userId
                 * @param {string} secret
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateMembershipStatus: (teamId, inviteId, userId, secret) => __awaiter(this, void 0, void 0, function* () {
                    if (teamId === undefined) {
                        throw new AppwriteException('Missing required parameter: "teamId"');
                    }
                    if (inviteId === undefined) {
                        throw new AppwriteException('Missing required parameter: "inviteId"');
                    }
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (secret === undefined) {
                        throw new AppwriteException('Missing required parameter: "secret"');
                    }
                    let path = '/teams/{teamId}/memberships/{inviteId}/status'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);
                    let payload = {};
                    if (typeof userId !== 'undefined') {
                        payload['userId'] = userId;
                    }
                    if (typeof secret !== 'undefined') {
                        payload['secret'] = secret;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                list: (search = '', limit = 25, offset = 0, orderType = 'ASC') => __awaiter(this, void 0, void 0, function* () {
                    let path = '/users';
                    let payload = {};
                    if (search) {
                        payload['search'] = search;
                    }
                    if (limit) {
                        payload['limit'] = limit;
                    }
                    if (offset) {
                        payload['offset'] = offset;
                    }
                    if (orderType) {
                        payload['orderType'] = orderType;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Create User
                 *
                 * Create a new user.
                 *
                 * @param {string} email
                 * @param {string} password
                 * @param {string} name
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                create: (email, password, name = '') => __awaiter(this, void 0, void 0, function* () {
                    if (email === undefined) {
                        throw new AppwriteException('Missing required parameter: "email"');
                    }
                    if (password === undefined) {
                        throw new AppwriteException('Missing required parameter: "password"');
                    }
                    let path = '/users';
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
                    return yield this.call('post', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get User
                 *
                 * Get a user by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                get: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete User
                 *
                 * Delete a user by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteUser: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get User Logs
                 *
                 * Get a user activity logs list by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getLogs: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/logs'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get User Preferences
                 *
                 * Get the user preferences by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getPrefs: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
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
                 * @return {Promise}
                 */
                updatePrefs: (userId, prefs) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (prefs === undefined) {
                        throw new AppwriteException('Missing required parameter: "prefs"');
                    }
                    let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    if (typeof prefs !== 'undefined') {
                        payload['prefs'] = prefs;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Get User Sessions
                 *
                 * Get the user sessions list by its unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                getSessions: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('get', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete User Sessions
                 *
                 * Delete all user's sessions by using the user's unique ID.
                 *
                 * @param {string} userId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteSessions: (userId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Delete User Session
                 *
                 * Delete a user sessions by its unique ID.
                 *
                 * @param {string} userId
                 * @param {string} sessionId
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                deleteSession: (userId, sessionId) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (sessionId === undefined) {
                        throw new AppwriteException('Missing required parameter: "sessionId"');
                    }
                    let path = '/users/{userId}/sessions/{sessionId}'.replace(new RegExp('{userId}', 'g'), userId).replace(new RegExp('{sessionId}', 'g'), sessionId);
                    let payload = {};
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('delete', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                }),
                /**
                 * Update User Status
                 *
                 * Update the user status by its unique ID.
                 *
                 * @param {string} userId
                 * @param {string} status
                 * @throws {AppwriteException}
                 * @return {Promise}
                 */
                updateStatus: (userId, status) => __awaiter(this, void 0, void 0, function* () {
                    if (userId === undefined) {
                        throw new AppwriteException('Missing required parameter: "userId"');
                    }
                    if (status === undefined) {
                        throw new AppwriteException('Missing required parameter: "status"');
                    }
                    let path = '/users/{userId}/status'.replace(new RegExp('{userId}', 'g'), userId);
                    let payload = {};
                    if (typeof status !== 'undefined') {
                        payload['status'] = status;
                    }
                    const uri = new URL(this.config.endpoint + path);
                    return yield this.call('patch', uri, new Headers({
                        'content-type': 'application/json',
                    }), payload);
                })
            };
        }
        /**
         * Set Endpoint
         *
         * Your project ID
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
            this.headers.set('X-Appwrite-Project', value);
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
            this.headers.set('X-Appwrite-Key', value);
            this.config.key = value;
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
            this.headers.set('X-Appwrite-Locale', value);
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
            this.headers.set('X-Appwrite-Mode', value);
            this.config.mode = value;
            return this;
        }
        call(method, url, headers = new Headers(), params = {}) {
            var _a;
            return __awaiter(this, void 0, void 0, function* () {
                method = method.toUpperCase();
                this.headers.forEach((value, key) => {
                    headers.set(key, value);
                });
                let options = {
                    method,
                    headers,
                    credentials: 'include'
                };
                if (window.localStorage) {
                    headers.set('X-Fallback-Cookies', (_a = window.localStorage.getItem('cookieFallback')) !== null && _a !== void 0 ? _a : "");
                }
                if (method === 'GET') {
                    url.search = new URLSearchParams(this.flatten(params)).toString();
                }
                else {
                    switch (headers.get('content-type')) {
                        case 'application/json':
                            options.body = JSON.stringify(params);
                            break;
                        case 'multipart/form-data':
                            let formData = new FormData();
                            for (const key in params) {
                                formData.append(key + (Array.isArray(params[key]) ? '[]' : ''), params[key]);
                            }
                            options.body = formData;
                            headers.delete('content-type');
                            break;
                    }
                }
                try {
                    const response = yield fetch(url.toString(), options);
                    const data = yield response.json();
                    if (400 <= response.status) {
                        throw new AppwriteException(data.message, response.status, data);
                    }
                    const cookieFallback = response.headers.get('X-Fallback-Cookies');
                    if (window.localStorage && cookieFallback) {
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
                let finalKey = prefix ? prefix + '[' + key + ']' : key;
                if (Array.isArray(value)) {
                    output = Object.assign(output, this.flatten(value, finalKey)); // @todo: handle name collision here if needed
                }
                else {
                    output[finalKey] = value;
                }
            }
            return output;
        }
    }

    exports.Appwrite = Appwrite;

})));
