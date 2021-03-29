(function (window) {
     
    'use strict';

    window.Appwrite = function () {

        let config = {
            endpoint: 'https://appwrite.io/v1',
            project: '',
            key: '',
            locale: '',
            mode: '',
        };

        /**
         * @param {string} endpoint
         * @returns {this}
         */
        let setEndpoint = function(endpoint) {
            config.endpoint = endpoint;

            return this;
        };

        /**
         * Set Project
         *
         * Your project ID
         *
         * @param value string
         *
         * @return this
         */
        let setProject = function (value)
        {
            http.addGlobalHeader('X-Appwrite-Project', value);

            config.project = value;

            return this;
        };

        /**
         * Set Key
         *
         * Your secret API key
         *
         * @param value string
         *
         * @return this
         */
        let setKey = function (value)
        {
            http.addGlobalHeader('X-Appwrite-Key', value);

            config.key = value;

            return this;
        };

        /**
         * Set Locale
         *
         * @param value string
         *
         * @return this
         */
        let setLocale = function (value)
        {
            http.addGlobalHeader('X-Appwrite-Locale', value);

            config.locale = value;

            return this;
        };

        /**
         * Set Mode
         *
         * @param value string
         *
         * @return this
         */
        let setMode = function (value)
        {
            http.addGlobalHeader('X-Appwrite-Mode', value);

            config.mode = value;

            return this;
        };

        let http = function(document) {
            let globalParams    = [],
                globalHeaders   = [];

            let addParam = function (url, param, value) {
                let a = document.createElement('a'), regex = /(?:\?|&amp;|&)+([^=]+)(?:=([^&]*))*/g;
                let match, str = [];
                a.href = url;
                param = encodeURIComponent(param);

                while (match = regex.exec(a.search)) if (param !== match[1]) str.push(match[1] + (match[2] ? "=" + match[2] : ""));

                str.push(param + (value ? "=" + encodeURIComponent(value) : ""));

                a.search = str.join("&");

                return a.href;
            };

            /**
             * @param {Object} params
             * @returns {string}
             */
            let buildQuery = function(params) {
                let str = [];

                for (let p in params) {
                    if(Array.isArray(params[p])) {
                        for (let index = 0; index < params[p].length; index++) {
                            let param = params[p][index];
                            str.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        str.push(encodeURIComponent(p) + "=" + encodeURIComponent(params[p]));
                    }
                }

                return str.join("&");
            };

            let addGlobalHeader = function(key, value) {
                globalHeaders[key] = {key: key.toLowerCase(), value: value.toLowerCase()};
            };
            
            let addGlobalParam = function(key, value) {
                globalParams.push({key: key, value: value});
            };

            addGlobalHeader('x-sdk-version', 'appwrite:web:1.0.0');
            addGlobalHeader('content-type', '');
    
            /**
             * @param {string} method
             * @param {string} path string
             * @param {Object} headers
             * @param {Object} params
             * @param {function} progress
             * @returns {Promise}
             */
            let call = function (method, path, headers = {}, params = {}, progress = null) {
                let i;

                path = config.endpoint + path;

                if (-1 === ['GET', 'POST', 'PUT', 'DELETE', 'TRACE', 'HEAD', 'OPTIONS', 'CONNECT', 'PATCH'].indexOf(method)) {
                    throw new Error('var method must contain a valid HTTP method name');
                }

                if (typeof path !== 'string') {
                    throw new Error('var path must be of type string');
                }

                if (typeof headers !== 'object') {
                    throw new Error('var headers must be of type object');
                }

                for (i = 0; i < globalParams.length; i++) { // Add global params to URL
                    path = addParam(path, globalParams[i].key, globalParams[i].value);
                }

                if(window.localStorage && window.localStorage.getItem('cookieFallback')) {
                    headers['X-Fallback-Cookies'] = window.localStorage.getItem('cookieFallback');
                }

                for (let key in globalHeaders) { // Add Global Headers
                    if (globalHeaders.hasOwnProperty(key)) {
                        if (!headers[globalHeaders[key].key]) {
                            headers[globalHeaders[key].key] = globalHeaders[key].value;
                        }
                    }
                }

                if(method === 'GET') {
                    for (let param in params) {
                        if (param.hasOwnProperty(key)) {
                            path = addParam(path, key + (Array.isArray(param) ? '[]' : ''), params[key]);
                        }
                    }
                }

                switch (headers['content-type']) { // Parse request by content type
                    case 'application/json':
                        params = JSON.stringify(params);
                    break;

                    case 'multipart/form-data':
                        let formData = new FormData();

                        Object.keys(params).forEach(function(key) {
                            let param = params[key];
                            formData.append(key + (Array.isArray(param) ? '[]' : ''), param);
                        });

                        params = formData;
                    break;
                }

                return new Promise(function (resolve, reject) {

                    let request = new XMLHttpRequest(), key;

                    request.withCredentials = true;
                    request.open(method, path, true);

                    for (key in headers) { // Set Headers
                        if (headers.hasOwnProperty(key)) {
                            if (key === 'content-type' && headers[key] === 'multipart/form-data') { // Skip to avoid missing boundary
                                continue;
                            }

                            request.setRequestHeader(key, headers[key]);
                        }
                    }

                    request.onload = function () {
                        let data = request.response;
                        let contentType = this.getResponseHeader('content-type') || '';
                        contentType = contentType.substring(0, contentType.indexOf(';'));

                        switch (contentType) {
                            case 'application/json':
                                data = JSON.parse(data);
                                break;
                        }

                        let cookieFallback = this.getResponseHeader('X-Fallback-Cookies') || '';
                        
                        if(window.localStorage && cookieFallback) {
                            window.console.warn('Appwrite is using localStorage for session management. Increase your security by adding a custom domain as your API endpoint.');
                            window.localStorage.setItem('cookieFallback', cookieFallback);
                        }

                        if (4 === request.readyState && 399 >= request.status) {
                            resolve(data);
                        } else {
                            reject(data);
                        }
                    };

                    if (progress) {
                        request.addEventListener('progress', progress);
                        request.upload.addEventListener('progress', progress, false);
                    }

                    // Handle network errors
                    request.onerror = function () {
                        reject(new Error("Network Error"));
                    };

                    request.send(params);
                })
            };

            return {
                'get': function(path, headers = {}, params = {}) {
                    return call('GET', path + ((Object.keys(params).length > 0) ? '?' + buildQuery(params) : ''), headers, {});
                },
                'post': function(path, headers = {}, params = {}, progress = null) {
                    return call('POST', path, headers, params, progress);
                },
                'put': function(path, headers = {}, params = {}, progress = null) {
                    return call('PUT', path, headers, params, progress);
                },
                'patch': function(path, headers = {}, params = {}, progress = null) {
                    return call('PATCH', path, headers, params, progress);
                },
                'delete': function(path, headers = {}, params = {}, progress = null) {
                    return call('DELETE', path, headers, params, progress);
                },
                'addGlobalParam': addGlobalParam,
                'addGlobalHeader': addGlobalHeader
            }
        }(window.document);

        let account = {

            /**
             * Get Account
             *
             * Get currently logged in user data as JSON object.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            get: function() {
                let path = '/account';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Account
             *
             * Use this endpoint to allow a new user to register a new account in your
             * project. After the user registration completes successfully, you can use
             * the [/account/verfication](/docs/client/account#createVerification) route
             * to start verifying the user email address. To allow the new user to login
             * to their new account, you need to create a new [account
             * session](/docs/client/account#createSession).
             *
             * @param {string} email
             * @param {string} password
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            create: function(email, password, name = '') {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/account';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(password) {
                    payload['password'] = password;
                }

                if(name) {
                    payload['name'] = name;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Account
             *
             * Delete a currently logged in user account. Behind the scene, the user
             * record is not deleted but permanently blocked from any access. This is done
             * to avoid deleted accounts being overtaken by new users with the same email
             * address. Any user-related resources like documents or storage files should
             * be deleted separately.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            delete: function() {
                let path = '/account';

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateEmail: function(email, password) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/account/email';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(password) {
                    payload['password'] = password;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Account JWT
             *
             * Use this endpoint to create a JSON Web Token. You can use the resulting JWT
             * to authenticate on behalf of the current user when working with the
             * Appwrite server-side API and SDKs. The JWT secret is valid for 15 minutes
             * from its creation and will be invalid if the user will logout.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            createJWT: function() {
                let path = '/account/jwt';

                let payload = {};

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Account Logs
             *
             * Get currently logged in user list of latest security activity logs. Each
             * log returns user IP address, location and date and time of log.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getLogs: function() {
                let path = '/account/logs';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Account Name
             *
             * Update currently logged in user account name.
             *
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            updateName: function(name) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/account/name';

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Account Password
             *
             * Update currently logged in user password. For validation, user is required
             * to pass the password twice.
             *
             * @param {string} password
             * @param {string} oldPassword
             * @throws {Error}
             * @return {Promise}             
             */
            updatePassword: function(password, oldPassword) {
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                if(oldPassword === undefined) {
                    throw new Error('Missing required parameter: "oldPassword"');
                }
                
                let path = '/account/password';

                let payload = {};

                if(password) {
                    payload['password'] = password;
                }

                if(oldPassword) {
                    payload['oldPassword'] = oldPassword;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Account Preferences
             *
             * Get currently logged in user preferences as a key-value object.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getPrefs: function() {
                let path = '/account/prefs';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Account Preferences
             *
             * Update currently logged in user account preferences. You can pass only the
             * specific settings you wish to update.
             *
             * @param {object} prefs
             * @throws {Error}
             * @return {Promise}             
             */
            updatePrefs: function(prefs) {
                if(prefs === undefined) {
                    throw new Error('Missing required parameter: "prefs"');
                }
                
                let path = '/account/prefs';

                let payload = {};

                if(prefs) {
                    payload['prefs'] = prefs;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @param {string} email
             * @param {string} url
             * @throws {Error}
             * @return {Promise}             
             */
            createRecovery: function(email, url) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/account/recovery';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(url) {
                    payload['url'] = url;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @param {string} userId
             * @param {string} secret
             * @param {string} password
             * @param {string} passwordAgain
             * @throws {Error}
             * @return {Promise}             
             */
            updateRecovery: function(userId, secret, password, passwordAgain) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(secret === undefined) {
                    throw new Error('Missing required parameter: "secret"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                if(passwordAgain === undefined) {
                    throw new Error('Missing required parameter: "passwordAgain"');
                }
                
                let path = '/account/recovery';

                let payload = {};

                if(userId) {
                    payload['userId'] = userId;
                }

                if(secret) {
                    payload['secret'] = secret;
                }

                if(password) {
                    payload['password'] = password;
                }

                if(passwordAgain) {
                    payload['passwordAgain'] = passwordAgain;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Account Sessions
             *
             * Get currently logged in user list of active sessions across different
             * devices.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getSessions: function() {
                let path = '/account/sessions';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Account Session
             *
             * Allow the user to login into their account by providing a valid email and
             * password combination. This route will create a new session for the user.
             *
             * @param {string} email
             * @param {string} password
             * @throws {Error}
             * @return {Promise}             
             */
            createSession: function(email, password) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/account/sessions';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(password) {
                    payload['password'] = password;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete All Account Sessions
             *
             * Delete all sessions from the user account and remove any sessions cookies
             * from the end client.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            deleteSessions: function() {
                let path = '/account/sessions';

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            createOAuth2Session: function(provider, success = 'https://appwrite.io/auth/oauth2/success', failure = 'https://appwrite.io/auth/oauth2/failure', scopes = []) {
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                let path = '/account/sessions/oauth2/{provider}'.replace(new RegExp('{provider}', 'g'), provider);

                let payload = {};

                if(success) {
                    payload['success'] = success;
                }

                if(failure) {
                    payload['failure'] = failure;
                }

                if(scopes) {
                    payload['scopes'] = scopes;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                window.location = config.endpoint + path + ((query) ? '?' + query : '');
            },

            /**
             * Delete Account Session
             *
             * Use this endpoint to log out the currently logged in user from all their
             * account sessions across all of their different devices. When using the
             * option id argument, only the session unique ID provider will be deleted.
             *
             * @param {string} sessionId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteSession: function(sessionId) {
                if(sessionId === undefined) {
                    throw new Error('Missing required parameter: "sessionId"');
                }
                
                let path = '/account/sessions/{sessionId}'.replace(new RegExp('{sessionId}', 'g'), sessionId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * process](/docs/client/account#updateVerification). 
             * 
             * Please note that in order to avoid a [Redirect
             * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md),
             * the only valid redirect URLs are the ones from domains you have set when
             * adding your platforms in the console interface.
             * 
             *
             * @param {string} url
             * @throws {Error}
             * @return {Promise}             
             */
            createVerification: function(url) {
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/account/verification';

                let payload = {};

                if(url) {
                    payload['url'] = url;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateVerification: function(userId, secret) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(secret === undefined) {
                    throw new Error('Missing required parameter: "secret"');
                }
                
                let path = '/account/verification';

                let payload = {};

                if(userId) {
                    payload['userId'] = userId;
                }

                if(secret) {
                    payload['secret'] = secret;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let avatars = {

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
             * @throws {Error}
             * @return {string}             
             */
            getBrowser: function(code, width = 100, height = 100, quality = 100) {
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/avatars/browsers/{code}'.replace(new RegExp('{code}', 'g'), code);

                let payload = {};

                if(width) {
                    payload['width'] = width;
                }

                if(height) {
                    payload['height'] = height;
                }

                if(quality) {
                    payload['quality'] = quality;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
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
             * @throws {Error}
             * @return {string}             
             */
            getCreditCard: function(code, width = 100, height = 100, quality = 100) {
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/avatars/credit-cards/{code}'.replace(new RegExp('{code}', 'g'), code);

                let payload = {};

                if(width) {
                    payload['width'] = width;
                }

                if(height) {
                    payload['height'] = height;
                }

                if(quality) {
                    payload['quality'] = quality;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
            },

            /**
             * Get Favicon
             *
             * Use this endpoint to fetch the favorite icon (AKA favicon) of any remote
             * website URL.
             * 
             *
             * @param {string} url
             * @throws {Error}
             * @return {string}             
             */
            getFavicon: function(url) {
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/avatars/favicon';

                let payload = {};

                if(url) {
                    payload['url'] = url;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
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
             * @throws {Error}
             * @return {string}             
             */
            getFlag: function(code, width = 100, height = 100, quality = 100) {
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/avatars/flags/{code}'.replace(new RegExp('{code}', 'g'), code);

                let payload = {};

                if(width) {
                    payload['width'] = width;
                }

                if(height) {
                    payload['height'] = height;
                }

                if(quality) {
                    payload['quality'] = quality;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
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
             * @throws {Error}
             * @return {string}             
             */
            getImage: function(url, width = 400, height = 400) {
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/avatars/image';

                let payload = {};

                if(url) {
                    payload['url'] = url;
                }

                if(width) {
                    payload['width'] = width;
                }

                if(height) {
                    payload['height'] = height;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
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
             * @throws {Error}
             * @return {string}             
             */
            getInitials: function(name = '', width = 500, height = 500, color = '', background = '') {
                let path = '/avatars/initials';

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(width) {
                    payload['width'] = width;
                }

                if(height) {
                    payload['height'] = height;
                }

                if(color) {
                    payload['color'] = color;
                }

                if(background) {
                    payload['background'] = background;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
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
             * @throws {Error}
             * @return {string}             
             */
            getQR: function(text, size = 400, margin = 1, download = false) {
                if(text === undefined) {
                    throw new Error('Missing required parameter: "text"');
                }
                
                let path = '/avatars/qr';

                let payload = {};

                if(text) {
                    payload['text'] = text;
                }

                if(size) {
                    payload['size'] = size;
                }

                if(margin) {
                    payload['margin'] = margin;
                }

                if(download) {
                    payload['download'] = download;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
            }
        };

        let database = {

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
             * @throws {Error}
             * @return {Promise}             
             */
            listCollections: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/database/collections';

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Collection
             *
             * Create a new Collection.
             *
             * @param {string} name
             * @param {string[]} read
             * @param {string[]} write
             * @param {string[]} rules
             * @throws {Error}
             * @return {Promise}             
             */
            createCollection: function(name, read, write, rules) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(read === undefined) {
                    throw new Error('Missing required parameter: "read"');
                }
                
                if(write === undefined) {
                    throw new Error('Missing required parameter: "write"');
                }
                
                if(rules === undefined) {
                    throw new Error('Missing required parameter: "rules"');
                }
                
                let path = '/database/collections';

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                if(rules) {
                    payload['rules'] = rules;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Collection
             *
             * Get a collection by its unique ID. This endpoint response returns a JSON
             * object with the collection metadata.
             *
             * @param {string} collectionId
             * @throws {Error}
             * @return {Promise}             
             */
            getCollection: function(collectionId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateCollection: function(collectionId, name, read, write, rules = []) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(read === undefined) {
                    throw new Error('Missing required parameter: "read"');
                }
                
                if(write === undefined) {
                    throw new Error('Missing required parameter: "write"');
                }
                
                let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                if(rules) {
                    payload['rules'] = rules;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Collection
             *
             * Delete a collection by its unique ID. Only users with write permissions
             * have access to delete this resource.
             *
             * @param {string} collectionId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteCollection: function(collectionId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/collections/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            listDocuments: function(collectionId, filters = [], limit = 25, offset = 0, orderField = '', orderType = 'ASC', orderCast = 'string', search = '') {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                if(filters) {
                    payload['filters'] = filters;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderField) {
                    payload['orderField'] = orderField;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                if(orderCast) {
                    payload['orderCast'] = orderCast;
                }

                if(search) {
                    payload['search'] = search;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Document
             *
             * Create a new Document. Before using this route, you should create a new
             * collection resource using either a [server
             * integration](/docs/server/database?sdk=nodejs#createCollection) API or
             * directly from your database console.
             *
             * @param {string} collectionId
             * @param {object} data
             * @param {string[]} read
             * @param {string[]} write
             * @param {string} parentDocument
             * @param {string} parentProperty
             * @param {string} parentPropertyType
             * @throws {Error}
             * @return {Promise}             
             */
            createDocument: function(collectionId, data, read, write, parentDocument = '', parentProperty = '', parentPropertyType = 'assign') {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(data === undefined) {
                    throw new Error('Missing required parameter: "data"');
                }
                
                if(read === undefined) {
                    throw new Error('Missing required parameter: "read"');
                }
                
                if(write === undefined) {
                    throw new Error('Missing required parameter: "write"');
                }
                
                let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                if(data) {
                    payload['data'] = data;
                }

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                if(parentDocument) {
                    payload['parentDocument'] = parentDocument;
                }

                if(parentProperty) {
                    payload['parentProperty'] = parentProperty;
                }

                if(parentPropertyType) {
                    payload['parentPropertyType'] = parentPropertyType;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Document
             *
             * Get a document by its unique ID. This endpoint response returns a JSON
             * object with the document data.
             *
             * @param {string} collectionId
             * @param {string} documentId
             * @throws {Error}
             * @return {Promise}             
             */
            getDocument: function(collectionId, documentId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(documentId === undefined) {
                    throw new Error('Missing required parameter: "documentId"');
                }
                
                let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateDocument: function(collectionId, documentId, data, read, write) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(documentId === undefined) {
                    throw new Error('Missing required parameter: "documentId"');
                }
                
                if(data === undefined) {
                    throw new Error('Missing required parameter: "data"');
                }
                
                if(read === undefined) {
                    throw new Error('Missing required parameter: "read"');
                }
                
                if(write === undefined) {
                    throw new Error('Missing required parameter: "write"');
                }
                
                let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                let payload = {};

                if(data) {
                    payload['data'] = data;
                }

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Document
             *
             * Delete a document by its unique ID. This endpoint deletes only the parent
             * documents, its attributes and relations to other documents. Child documents
             * **will not** be deleted.
             *
             * @param {string} collectionId
             * @param {string} documentId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteDocument: function(collectionId, documentId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(documentId === undefined) {
                    throw new Error('Missing required parameter: "documentId"');
                }
                
                let path = '/database/collections/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let functions = {

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
             * @throws {Error}
             * @return {Promise}             
             */
            list: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/functions';

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            create: function(name, execute, env, vars = {}, events = [], schedule = '', timeout = 15) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(execute === undefined) {
                    throw new Error('Missing required parameter: "execute"');
                }
                
                if(env === undefined) {
                    throw new Error('Missing required parameter: "env"');
                }
                
                let path = '/functions';

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(execute) {
                    payload['execute'] = execute;
                }

                if(env) {
                    payload['env'] = env;
                }

                if(vars) {
                    payload['vars'] = vars;
                }

                if(events) {
                    payload['events'] = events;
                }

                if(schedule) {
                    payload['schedule'] = schedule;
                }

                if(timeout) {
                    payload['timeout'] = timeout;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Function
             *
             * Get a function by its unique ID.
             *
             * @param {string} functionId
             * @throws {Error}
             * @return {Promise}             
             */
            get: function(functionId) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            update: function(functionId, name, execute, vars = {}, events = [], schedule = '', timeout = 15) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(execute === undefined) {
                    throw new Error('Missing required parameter: "execute"');
                }
                
                let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(execute) {
                    payload['execute'] = execute;
                }

                if(vars) {
                    payload['vars'] = vars;
                }

                if(events) {
                    payload['events'] = events;
                }

                if(schedule) {
                    payload['schedule'] = schedule;
                }

                if(timeout) {
                    payload['timeout'] = timeout;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Function
             *
             * Delete a function by its unique ID.
             *
             * @param {string} functionId
             * @throws {Error}
             * @return {Promise}             
             */
            delete: function(functionId) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                let path = '/functions/{functionId}'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            listExecutions: function(functionId, search = '', limit = 25, offset = 0, orderType = 'ASC') {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Execution
             *
             * Trigger a function execution. The returned object will return you the
             * current execution status. You can ping the `Get Execution` endpoint to get
             * updates on the current execution status. Once this endpoint is called, your
             * function execution process will start asynchronously.
             *
             * @param {string} functionId
             * @param {string} data 
             * @throws {Error}
             * @return {Promise}             
             */
            createExecution: function(functionId, data) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                let path = '/functions/{functionId}/executions'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if (data) {
                    payload['data'] = data;   
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Execution
             *
             * Get a function execution log by its unique ID.
             *
             * @param {string} functionId
             * @param {string} executionId
             * @throws {Error}
             * @return {Promise}             
             */
            getExecution: function(functionId, executionId) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                if(executionId === undefined) {
                    throw new Error('Missing required parameter: "executionId"');
                }
                
                let path = '/functions/{functionId}/executions/{executionId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{executionId}', 'g'), executionId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Function Tag
             *
             * Update the function code tag ID using the unique function ID. Use this
             * endpoint to switch the code tag that should be executed by the execution
             * endpoint.
             *
             * @param {string} functionId
             * @param {string} tag
             * @throws {Error}
             * @return {Promise}             
             */
            updateTag: function(functionId, tag) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                if(tag === undefined) {
                    throw new Error('Missing required parameter: "tag"');
                }
                
                let path = '/functions/{functionId}/tag'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if(tag) {
                    payload['tag'] = tag;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            listTags: function(functionId, search = '', limit = 25, offset = 0, orderType = 'ASC') {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            createTag: function(functionId, command, code) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                if(command === undefined) {
                    throw new Error('Missing required parameter: "command"');
                }
                
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/functions/{functionId}/tags'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if(command) {
                    payload['command'] = command;
                }

                if(code) {
                    payload['code'] = code;
                }

                return http
                    .post(path, {
                        'content-type': 'multipart/form-data',
                    }, payload);
            },

            /**
             * Get Tag
             *
             * Get a code tag by its unique ID.
             *
             * @param {string} functionId
             * @param {string} tagId
             * @throws {Error}
             * @return {Promise}             
             */
            getTag: function(functionId, tagId) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                if(tagId === undefined) {
                    throw new Error('Missing required parameter: "tagId"');
                }
                
                let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Tag
             *
             * Delete a code tag by its unique ID.
             *
             * @param {string} functionId
             * @param {string} tagId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteTag: function(functionId, tagId) {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                if(tagId === undefined) {
                    throw new Error('Missing required parameter: "tagId"');
                }
                
                let path = '/functions/{functionId}/tags/{tagId}'.replace(new RegExp('{functionId}', 'g'), functionId).replace(new RegExp('{tagId}', 'g'), tagId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Function Usage
             *
             *
             * @param {string} functionId
             * @param {string} range
             * @throws {Error}
             * @return {Promise}             
             */
            getUsage: function(functionId, range = '30d') {
                if(functionId === undefined) {
                    throw new Error('Missing required parameter: "functionId"');
                }
                
                let path = '/functions/{functionId}/usage'.replace(new RegExp('{functionId}', 'g'), functionId);

                let payload = {};

                if(range) {
                    payload['range'] = range;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let health = {

            /**
             * Get HTTP
             *
             * Check the Appwrite HTTP server is up and responsive.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            get: function() {
                let path = '/health';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Anti virus
             *
             * Check the Appwrite Anti Virus server is up and connection is successful.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getAntiVirus: function() {
                let path = '/health/anti-virus';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Cache
             *
             * Check the Appwrite in-memory cache server is up and connection is
             * successful.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCache: function() {
                let path = '/health/cache';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get DB
             *
             * Check the Appwrite database server is up and connection is successful.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getDB: function() {
                let path = '/health/db';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Certificate Queue
             *
             * Get the number of certificates that are waiting to be issued against
             * [Letsencrypt](https://letsencrypt.org/) in the Appwrite internal queue
             * server.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getQueueCertificates: function() {
                let path = '/health/queue/certificates';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Functions Queue
             *
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getQueueFunctions: function() {
                let path = '/health/queue/functions';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Logs Queue
             *
             * Get the number of logs that are waiting to be processed in the Appwrite
             * internal queue server.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getQueueLogs: function() {
                let path = '/health/queue/logs';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Tasks Queue
             *
             * Get the number of tasks that are waiting to be processed in the Appwrite
             * internal queue server.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getQueueTasks: function() {
                let path = '/health/queue/tasks';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Usage Queue
             *
             * Get the number of usage stats that are waiting to be processed in the
             * Appwrite internal queue server.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getQueueUsage: function() {
                let path = '/health/queue/usage';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Webhooks Queue
             *
             * Get the number of webhooks that are waiting to be processed in the Appwrite
             * internal queue server.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getQueueWebhooks: function() {
                let path = '/health/queue/webhooks';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Local Storage
             *
             * Check the Appwrite local storage device is up and connection is successful.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getStorageLocal: function() {
                let path = '/health/storage/local';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            getTime: function() {
                let path = '/health/time';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let locale = {

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
             * @throws {Error}
             * @return {Promise}             
             */
            get: function() {
                let path = '/locale';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Continents
             *
             * List of all continents. You can use the locale header to get the data in a
             * supported language.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getContinents: function() {
                let path = '/locale/continents';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Countries
             *
             * List of all countries. You can use the locale header to get the data in a
             * supported language.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCountries: function() {
                let path = '/locale/countries';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List EU Countries
             *
             * List of all countries that are currently members of the EU. You can use the
             * locale header to get the data in a supported language.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCountriesEU: function() {
                let path = '/locale/countries/eu';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Countries Phone Codes
             *
             * List of all countries phone codes. You can use the locale header to get the
             * data in a supported language.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCountriesPhones: function() {
                let path = '/locale/countries/phones';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Currencies
             *
             * List of all currencies, including currency symbol, name, plural, and
             * decimal digits for all major and minor currencies. You can use the locale
             * header to get the data in a supported language.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCurrencies: function() {
                let path = '/locale/currencies';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Languages
             *
             * List of all languages classified by ISO 639-1 including 2-letter code, name
             * in English, and name in the respective language.
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getLanguages: function() {
                let path = '/locale/languages';

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let projects = {

            /**
             * List Projects
             *
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             
             */
            list: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/projects';

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            create: function(name, teamId, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/projects';

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(teamId) {
                    payload['teamId'] = teamId;
                }

                if(description) {
                    payload['description'] = description;
                }

                if(logo) {
                    payload['logo'] = logo;
                }

                if(url) {
                    payload['url'] = url;
                }

                if(legalName) {
                    payload['legalName'] = legalName;
                }

                if(legalCountry) {
                    payload['legalCountry'] = legalCountry;
                }

                if(legalState) {
                    payload['legalState'] = legalState;
                }

                if(legalCity) {
                    payload['legalCity'] = legalCity;
                }

                if(legalAddress) {
                    payload['legalAddress'] = legalAddress;
                }

                if(legalTaxId) {
                    payload['legalTaxId'] = legalTaxId;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            get: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            update: function(projectId, name, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(description) {
                    payload['description'] = description;
                }

                if(logo) {
                    payload['logo'] = logo;
                }

                if(url) {
                    payload['url'] = url;
                }

                if(legalName) {
                    payload['legalName'] = legalName;
                }

                if(legalCountry) {
                    payload['legalCountry'] = legalCountry;
                }

                if(legalState) {
                    payload['legalState'] = legalState;
                }

                if(legalCity) {
                    payload['legalCity'] = legalCity;
                }

                if(legalAddress) {
                    payload['legalAddress'] = legalAddress;
                }

                if(legalTaxId) {
                    payload['legalTaxId'] = legalTaxId;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Project
             *
             *
             * @param {string} projectId
             * @param {string} password
             * @throws {Error}
             * @return {Promise}             
             */
            delete: function(projectId, password) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(password) {
                    payload['password'] = password;
                }

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Domains
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            listDomains: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/domains'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Domain
             *
             *
             * @param {string} projectId
             * @param {string} domain
             * @throws {Error}
             * @return {Promise}             
             */
            createDomain: function(projectId, domain) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(domain === undefined) {
                    throw new Error('Missing required parameter: "domain"');
                }
                
                let path = '/projects/{projectId}/domains'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(domain) {
                    payload['domain'] = domain;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Domain
             *
             *
             * @param {string} projectId
             * @param {string} domainId
             * @throws {Error}
             * @return {Promise}             
             */
            getDomain: function(projectId, domainId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(domainId === undefined) {
                    throw new Error('Missing required parameter: "domainId"');
                }
                
                let path = '/projects/{projectId}/domains/{domainId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{domainId}', 'g'), domainId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Domain
             *
             *
             * @param {string} projectId
             * @param {string} domainId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteDomain: function(projectId, domainId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(domainId === undefined) {
                    throw new Error('Missing required parameter: "domainId"');
                }
                
                let path = '/projects/{projectId}/domains/{domainId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{domainId}', 'g'), domainId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Domain Verification Status
             *
             *
             * @param {string} projectId
             * @param {string} domainId
             * @throws {Error}
             * @return {Promise}             
             */
            updateDomainVerification: function(projectId, domainId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(domainId === undefined) {
                    throw new Error('Missing required parameter: "domainId"');
                }
                
                let path = '/projects/{projectId}/domains/{domainId}/verification'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{domainId}', 'g'), domainId);

                let payload = {};

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Keys
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            listKeys: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Key
             *
             *
             * @param {string} projectId
             * @param {string} name
             * @param {string[]} scopes
             * @throws {Error}
             * @return {Promise}             
             */
            createKey: function(projectId, name, scopes) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(scopes === undefined) {
                    throw new Error('Missing required parameter: "scopes"');
                }
                
                let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(scopes) {
                    payload['scopes'] = scopes;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Key
             *
             *
             * @param {string} projectId
             * @param {string} keyId
             * @throws {Error}
             * @return {Promise}             
             */
            getKey: function(projectId, keyId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(keyId === undefined) {
                    throw new Error('Missing required parameter: "keyId"');
                }
                
                let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Key
             *
             *
             * @param {string} projectId
             * @param {string} keyId
             * @param {string} name
             * @param {string[]} scopes
             * @throws {Error}
             * @return {Promise}             
             */
            updateKey: function(projectId, keyId, name, scopes) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(keyId === undefined) {
                    throw new Error('Missing required parameter: "keyId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(scopes === undefined) {
                    throw new Error('Missing required parameter: "scopes"');
                }
                
                let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(scopes) {
                    payload['scopes'] = scopes;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Key
             *
             *
             * @param {string} projectId
             * @param {string} keyId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteKey: function(projectId, keyId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(keyId === undefined) {
                    throw new Error('Missing required parameter: "keyId"');
                }
                
                let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Project OAuth2
             *
             *
             * @param {string} projectId
             * @param {string} provider
             * @param {string} appId
             * @param {string} secret
             * @throws {Error}
             * @return {Promise}             
             */
            updateOAuth2: function(projectId, provider, appId = '', secret = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                let path = '/projects/{projectId}/oauth2'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(provider) {
                    payload['provider'] = provider;
                }

                if(appId) {
                    payload['appId'] = appId;
                }

                if(secret) {
                    payload['secret'] = secret;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Platforms
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            listPlatforms: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            createPlatform: function(projectId, type, name, key = '', store = '', hostname = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(type === undefined) {
                    throw new Error('Missing required parameter: "type"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(type) {
                    payload['type'] = type;
                }

                if(name) {
                    payload['name'] = name;
                }

                if(key) {
                    payload['key'] = key;
                }

                if(store) {
                    payload['store'] = store;
                }

                if(hostname) {
                    payload['hostname'] = hostname;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Platform
             *
             *
             * @param {string} projectId
             * @param {string} platformId
             * @throws {Error}
             * @return {Promise}             
             */
            getPlatform: function(projectId, platformId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(platformId === undefined) {
                    throw new Error('Missing required parameter: "platformId"');
                }
                
                let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updatePlatform: function(projectId, platformId, name, key = '', store = '', hostname = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(platformId === undefined) {
                    throw new Error('Missing required parameter: "platformId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(key) {
                    payload['key'] = key;
                }

                if(store) {
                    payload['store'] = store;
                }

                if(hostname) {
                    payload['hostname'] = hostname;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Platform
             *
             *
             * @param {string} projectId
             * @param {string} platformId
             * @throws {Error}
             * @return {Promise}             
             */
            deletePlatform: function(projectId, platformId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(platformId === undefined) {
                    throw new Error('Missing required parameter: "platformId"');
                }
                
                let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Tasks
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            listTasks: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            createTask: function(projectId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = [], httpUser = '', httpPass = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(status === undefined) {
                    throw new Error('Missing required parameter: "status"');
                }
                
                if(schedule === undefined) {
                    throw new Error('Missing required parameter: "schedule"');
                }
                
                if(security === undefined) {
                    throw new Error('Missing required parameter: "security"');
                }
                
                if(httpMethod === undefined) {
                    throw new Error('Missing required parameter: "httpMethod"');
                }
                
                if(httpUrl === undefined) {
                    throw new Error('Missing required parameter: "httpUrl"');
                }
                
                let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(status) {
                    payload['status'] = status;
                }

                if(schedule) {
                    payload['schedule'] = schedule;
                }

                if(security) {
                    payload['security'] = security;
                }

                if(httpMethod) {
                    payload['httpMethod'] = httpMethod;
                }

                if(httpUrl) {
                    payload['httpUrl'] = httpUrl;
                }

                if(httpHeaders) {
                    payload['httpHeaders'] = httpHeaders;
                }

                if(httpUser) {
                    payload['httpUser'] = httpUser;
                }

                if(httpPass) {
                    payload['httpPass'] = httpPass;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Task
             *
             *
             * @param {string} projectId
             * @param {string} taskId
             * @throws {Error}
             * @return {Promise}             
             */
            getTask: function(projectId, taskId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(taskId === undefined) {
                    throw new Error('Missing required parameter: "taskId"');
                }
                
                let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateTask: function(projectId, taskId, name, status, schedule, security, httpMethod, httpUrl, httpHeaders = [], httpUser = '', httpPass = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(taskId === undefined) {
                    throw new Error('Missing required parameter: "taskId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(status === undefined) {
                    throw new Error('Missing required parameter: "status"');
                }
                
                if(schedule === undefined) {
                    throw new Error('Missing required parameter: "schedule"');
                }
                
                if(security === undefined) {
                    throw new Error('Missing required parameter: "security"');
                }
                
                if(httpMethod === undefined) {
                    throw new Error('Missing required parameter: "httpMethod"');
                }
                
                if(httpUrl === undefined) {
                    throw new Error('Missing required parameter: "httpUrl"');
                }
                
                let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(status) {
                    payload['status'] = status;
                }

                if(schedule) {
                    payload['schedule'] = schedule;
                }

                if(security) {
                    payload['security'] = security;
                }

                if(httpMethod) {
                    payload['httpMethod'] = httpMethod;
                }

                if(httpUrl) {
                    payload['httpUrl'] = httpUrl;
                }

                if(httpHeaders) {
                    payload['httpHeaders'] = httpHeaders;
                }

                if(httpUser) {
                    payload['httpUser'] = httpUser;
                }

                if(httpPass) {
                    payload['httpPass'] = httpPass;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Task
             *
             *
             * @param {string} projectId
             * @param {string} taskId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteTask: function(projectId, taskId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(taskId === undefined) {
                    throw new Error('Missing required parameter: "taskId"');
                }
                
                let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Project
             *
             *
             * @param {string} projectId
             * @param {string} range
             * @throws {Error}
             * @return {Promise}             
             */
            getUsage: function(projectId, range = '30d') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/usage'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(range) {
                    payload['range'] = range;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * List Webhooks
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            listWebhooks: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            createWebhook: function(projectId, name, events, url, security, httpUser = '', httpPass = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(events === undefined) {
                    throw new Error('Missing required parameter: "events"');
                }
                
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                if(security === undefined) {
                    throw new Error('Missing required parameter: "security"');
                }
                
                let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(events) {
                    payload['events'] = events;
                }

                if(url) {
                    payload['url'] = url;
                }

                if(security) {
                    payload['security'] = security;
                }

                if(httpUser) {
                    payload['httpUser'] = httpUser;
                }

                if(httpPass) {
                    payload['httpPass'] = httpPass;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Webhook
             *
             *
             * @param {string} projectId
             * @param {string} webhookId
             * @throws {Error}
             * @return {Promise}             
             */
            getWebhook: function(projectId, webhookId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(webhookId === undefined) {
                    throw new Error('Missing required parameter: "webhookId"');
                }
                
                let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateWebhook: function(projectId, webhookId, name, events, url, security, httpUser = '', httpPass = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(webhookId === undefined) {
                    throw new Error('Missing required parameter: "webhookId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(events === undefined) {
                    throw new Error('Missing required parameter: "events"');
                }
                
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                if(security === undefined) {
                    throw new Error('Missing required parameter: "security"');
                }
                
                let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(events) {
                    payload['events'] = events;
                }

                if(url) {
                    payload['url'] = url;
                }

                if(security) {
                    payload['security'] = security;
                }

                if(httpUser) {
                    payload['httpUser'] = httpUser;
                }

                if(httpPass) {
                    payload['httpPass'] = httpPass;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Webhook
             *
             *
             * @param {string} projectId
             * @param {string} webhookId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteWebhook: function(projectId, webhookId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(webhookId === undefined) {
                    throw new Error('Missing required parameter: "webhookId"');
                }
                
                let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let storage = {

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
             * @throws {Error}
             * @return {Promise}             
             */
            listFiles: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/storage/files';

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            createFile: function(file, read, write) {
                if(file === undefined) {
                    throw new Error('Missing required parameter: "file"');
                }
                
                if(read === undefined) {
                    throw new Error('Missing required parameter: "read"');
                }
                
                if(write === undefined) {
                    throw new Error('Missing required parameter: "write"');
                }
                
                let path = '/storage/files';

                let payload = {};

                if(file) {
                    payload['file'] = file;
                }

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                return http
                    .post(path, {
                        'content-type': 'multipart/form-data',
                    }, payload);
            },

            /**
             * Get File
             *
             * Get a file by its unique ID. This endpoint response returns a JSON object
             * with the file metadata.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {Promise}             
             */
            getFile: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update File
             *
             * Update a file by its unique ID. Only users with write permissions have
             * access to update this resource.
             *
             * @param {string} fileId
             * @param {string[]} read
             * @param {string[]} write
             * @throws {Error}
             * @return {Promise}             
             */
            updateFile: function(fileId, read, write) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                if(read === undefined) {
                    throw new Error('Missing required parameter: "read"');
                }
                
                if(write === undefined) {
                    throw new Error('Missing required parameter: "write"');
                }
                
                let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete File
             *
             * Delete a file by its unique ID. Only users with write permissions have
             * access to delete this resource.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteFile: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get File for Download
             *
             * Get a file content by its unique ID. The endpoint response return with a
             * 'Content-Disposition: attachment' header that tells the browser to start
             * downloading the file to user downloads directory.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {string}             
             */
            getFileDownload: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/download'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
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
             * @throws {Error}
             * @return {string}             
             */
            getFilePreview: function(fileId, width = 0, height = 0, quality = 100, background = '', output = '') {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/preview'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                if(width) {
                    payload['width'] = width;
                }

                if(height) {
                    payload['height'] = height;
                }

                if(quality) {
                    payload['quality'] = quality;
                }

                if(background) {
                    payload['background'] = background;
                }

                if(output) {
                    payload['output'] = output;
                }

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
            },

            /**
             * Get File for View
             *
             * Get a file content by its unique ID. This endpoint is similar to the
             * download method but returns with no  'Content-Disposition: attachment'
             * header.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {string}             
             */
            getFileView: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/view'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                payload['project'] = config.project;

                payload['key'] = config.key;


                let query = [];

                for (let p in payload) {
                    if(Array.isArray(payload[p])) {
                        for (let index = 0; index < payload[p].length; index++) {
                            let param = payload[p][index];
                            query.push(encodeURIComponent(p + '[]') + "=" + encodeURIComponent(param));
                        }
                    }
                    else {
                        query.push(encodeURIComponent(p) + "=" + encodeURIComponent(payload[p]));
                    }
                }

                query =  query.join("&");
                
                return config.endpoint + path + ((query) ? '?' + query : '');
            }
        };

        let teams = {

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
             * @throws {Error}
             * @return {Promise}             
             */
            list: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/teams';

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            create: function(name, roles = ["owner"]) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/teams';

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                if(roles) {
                    payload['roles'] = roles;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Team
             *
             * Get a team by its unique ID. All team members have read access for this
             * resource.
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             
             */
            get: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update Team
             *
             * Update a team by its unique ID. Only team owners have write access for this
             * resource.
             *
             * @param {string} teamId
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            update: function(teamId, name) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                if(name) {
                    payload['name'] = name;
                }

                return http
                    .put(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Team
             *
             * Delete a team by its unique ID. Only team owners have write access for this
             * resource.
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             
             */
            delete: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            getMemberships: function(teamId, search = '', limit = 25, offset = 0, orderType = 'ASC') {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Team Membership
             *
             * Use this endpoint to invite a new member to join your team. An email with a
             * link to join the team will be sent to the new member email address if the
             * member doesn't exist in the project it will be created automatically.
             * 
             * Use the 'URL' parameter to redirect the user from the invitation email back
             * to your app. When the user is redirected, use the [Update Team Membership
             * Status](/docs/client/teams#updateMembershipStatus) endpoint to allow the
             * user to accept the invitation to the team.
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
             * @throws {Error}
             * @return {Promise}             
             */
            createMembership: function(teamId, email, roles, url, name = '') {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(roles === undefined) {
                    throw new Error('Missing required parameter: "roles"');
                }
                
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(name) {
                    payload['name'] = name;
                }

                if(roles) {
                    payload['roles'] = roles;
                }

                if(url) {
                    payload['url'] = url;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete Team Membership
             *
             * This endpoint allows a user to leave a team or for a team owner to delete
             * the membership of any other team member. You can also use this endpoint to
             * delete a user membership even if it is not accepted.
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteMembership: function(teamId, inviteId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(inviteId === undefined) {
                    throw new Error('Missing required parameter: "inviteId"');
                }
                
                let path = '/teams/{teamId}/memberships/{inviteId}'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

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
             * @throws {Error}
             * @return {Promise}             
             */
            updateMembershipStatus: function(teamId, inviteId, userId, secret) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(inviteId === undefined) {
                    throw new Error('Missing required parameter: "inviteId"');
                }
                
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(secret === undefined) {
                    throw new Error('Missing required parameter: "secret"');
                }
                
                let path = '/teams/{teamId}/memberships/{inviteId}/status'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);

                let payload = {};

                if(userId) {
                    payload['userId'] = userId;
                }

                if(secret) {
                    payload['secret'] = secret;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let users = {

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
             * @throws {Error}
             * @return {Promise}             
             */
            list: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/users';

                let payload = {};

                if(search) {
                    payload['search'] = search;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(orderType) {
                    payload['orderType'] = orderType;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create User
             *
             * Create a new user.
             *
             * @param {string} email
             * @param {string} password
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            create: function(email, password, name = '') {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/users';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(password) {
                    payload['password'] = password;
                }

                if(name) {
                    payload['name'] = name;
                }

                return http
                    .post(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get User
             *
             * Get a user by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            get: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete User
             *
             * Delete a user by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteUser: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get User Logs
             *
             * Get a user activity logs list by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getLogs: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/logs'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get User Preferences
             *
             * Get the user preferences by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getPrefs: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update User Preferences
             *
             * Update the user preferences by its unique ID. You can pass only the
             * specific settings you wish to update.
             *
             * @param {string} userId
             * @param {object} prefs
             * @throws {Error}
             * @return {Promise}             
             */
            updatePrefs: function(userId, prefs) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(prefs === undefined) {
                    throw new Error('Missing required parameter: "prefs"');
                }
                
                let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                if(prefs) {
                    payload['prefs'] = prefs;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get User Sessions
             *
             * Get the user sessions list by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getSessions: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete User Sessions
             *
             * Delete all user's sessions by using the user's unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteSessions: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Delete User Session
             *
             * Delete a user sessions by its unique ID.
             *
             * @param {string} userId
             * @param {string} sessionId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteSession: function(userId, sessionId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(sessionId === undefined) {
                    throw new Error('Missing required parameter: "sessionId"');
                }
                
                let path = '/users/{userId}/sessions/{sessionId}'.replace(new RegExp('{userId}', 'g'), userId).replace(new RegExp('{sessionId}', 'g'), sessionId);

                let payload = {};

                return http
                    .delete(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Update User Status
             *
             * Update the user status by its unique ID.
             *
             * @param {string} userId
             * @param {string} status
             * @throws {Error}
             * @return {Promise}             
             */
            updateStatus: function(userId, status) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(status === undefined) {
                    throw new Error('Missing required parameter: "status"');
                }
                
                let path = '/users/{userId}/status'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                if(status) {
                    payload['status'] = status;
                }

                return http
                    .patch(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        return {
            setEndpoint: setEndpoint,
            setProject: setProject,
            setKey: setKey,
            setLocale: setLocale,
            setMode: setMode,
            account: account,
            avatars: avatars,
            database: database,
            functions: functions,
            health: health,
            locale: locale,
            projects: projects,
            storage: storage,
            teams: teams,
            users: users
        };
    };

    if(typeof module !== "undefined") {
        module.exports = window.Appwrite;
    }

})((typeof window !== "undefined") ? window : {});