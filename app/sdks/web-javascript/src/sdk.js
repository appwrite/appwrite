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

            addGlobalHeader('x-sdk-version', 'appwrite:javascript:1.0.29');
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
                        if (4 === request.readyState && 399 >= request.status) {
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

                            resolve(data);

                        } else {
                            reject(new Error(request.statusText));
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
             * the [/account/verfication](/docs/account#createVerification) route to start
             * verifying the user email address. To allow your new user to login to his
             * new account, you need to create a new [account
             * session](/docs/account#createSession).
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
                    payload['old-password'] = oldPassword;
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
             * request to the [PUT /account/recovery](/docs/account#updateRecovery)
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
             * /account/recovery](/docs/account#createRecovery) endpoint.
             * 
             * Please note that in order to avoid a [Redirect
             * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
             * the only valid redirect URLs are the ones from domains you have set when
             * adding your platforms in the console interface.
             *
             * @param {string} userId
             * @param {string} secret
             * @param {string} passwordA
             * @param {string} passwordB
             * @throws {Error}
             * @return {Promise}             
             */
            updateRecovery: function(userId, secret, passwordA, passwordB) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(secret === undefined) {
                    throw new Error('Missing required parameter: "secret"');
                }
                
                if(passwordA === undefined) {
                    throw new Error('Missing required parameter: "passwordA"');
                }
                
                if(passwordB === undefined) {
                    throw new Error('Missing required parameter: "passwordB"');
                }
                
                let path = '/account/recovery';

                let payload = {};

                if(userId) {
                    payload['userId'] = userId;
                }

                if(secret) {
                    payload['secret'] = secret;
                }

                if(passwordA) {
                    payload['password-a'] = passwordA;
                }

                if(passwordB) {
                    payload['password-b'] = passwordB;
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
             * Allow the user to login into his account by providing a valid email and
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
             * Allow the user to login to his account using the OAuth2 provider of his
             * choice. Each OAuth2 provider should be enabled from the Appwrite console
             * first. Use the success and failure arguments to provide a redirect URL's
             * back to your app when login is completed.
             *
             * @param {string} provider
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {Promise}             
             */
            createOAuth2Session: function(provider, success = 'https://appwrite.io/auth/oauth2/success', failure = 'https://appwrite.io/auth/oauth2/failure') {
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

                payload['project'] = config.project;

                let query = Object.keys(payload).map(key => key + '=' + encodeURIComponent(payload[key])).join('&');
                
                window.location = config.endpoint + path + ((query) ? '?' + query : '');
            },

            /**
             * Delete Account Session
             *
             * Use this endpoint to log out the currently logged in user from all his
             * account sessions across all his different devices. When using the option id
             * argument, only the session unique ID provider will be deleted.
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
             * have provider to be attached to the verification email. The provided URL
             * should redirect the user back for your app and allow you to complete the
             * verification process by verifying both the **userId** and **secret**
             * parameters. Learn more about how to [complete the verification
             * process](/docs/account#updateAccountVerification). 
             * 
             * Please note that in order to avoid a [Redirect
             * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
             * the only valid redirect URLs are the ones from domains you have set when
             * adding your platforms in the console interface.
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
             * @return {Promise}             
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

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Credit Card Icon
             *
             * Need to display your users with your billing method or their payment
             * methods? The credit card endpoint will return you the icon of the credit
             * card provider you need. Use width, height and quality arguments to change
             * the output settings.
             *
             * @param {string} code
             * @param {number} width
             * @param {number} height
             * @param {number} quality
             * @throws {Error}
             * @return {Promise}             
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

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Get Favicon
             *
             * Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
             * website URL.
             *
             * @param {string} url
             * @throws {Error}
             * @return {Promise}             
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

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
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
             * @return {Promise}             
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

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
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
             * @return {Promise}             
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

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
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
             * @param {number} download
             * @throws {Error}
             * @return {Promise}             
             */
            getQR: function(text, size = 400, margin = 1, download = 0) {
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

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            }
        };

        let database = {

            /**
             * List Documents
             *
             * Get a list of all the user documents. You can use the query params to
             * filter your results. On admin mode, this endpoint will return a list of all
             * of the project documents. [Learn more about different API
             * modes](/docs/admin).
             *
             * @param {string} collectionId
             * @param {string[]} filters
             * @param {number} offset
             * @param {number} limit
             * @param {string} orderField
             * @param {string} orderType
             * @param {string} orderCast
             * @param {string} search
             * @param {number} first
             * @param {number} last
             * @throws {Error}
             * @return {Promise}             
             */
            listDocuments: function(collectionId, filters = [], offset = 0, limit = 50, orderField = '$id', orderType = 'ASC', orderCast = 'string', search = '', first = 0, last = 0) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/collections/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                if(filters) {
                    payload['filters'] = filters;
                }

                if(offset) {
                    payload['offset'] = offset;
                }

                if(limit) {
                    payload['limit'] = limit;
                }

                if(orderField) {
                    payload['order-field'] = orderField;
                }

                if(orderType) {
                    payload['order-type'] = orderType;
                }

                if(orderCast) {
                    payload['order-cast'] = orderCast;
                }

                if(search) {
                    payload['search'] = search;
                }

                if(first) {
                    payload['first'] = first;
                }

                if(last) {
                    payload['last'] = last;
                }

                return http
                    .get(path, {
                        'content-type': 'application/json',
                    }, payload);
            },

            /**
             * Create Document
             *
             * Create a new Document.
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
             * Get document by its unique ID. This endpoint response returns a JSON object
             * with the document data.
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
             * Delete document by its unique ID. This endpoint deletes only the parent
             * documents, his attributes and relations to other documents. Child documents
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
             * List of all currencies, including currency symol, name, plural, and decimal
             * digits for all major and minor currencies. You can use the locale header to
             * get the data in a supported language.
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
            }
        };

        let storage = {

            /**
             * List Files
             *
             * Get a list of all the user files. You can use the query params to filter
             * your results. On admin mode, this endpoint will return a list of all of the
             * project files. [Learn more about different API modes](/docs/admin).
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
             * Get file by its unique ID. This endpoint response returns a JSON object
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
             * Update file by its unique ID. Only users with write permissions have access
             * to update this resource.
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
             * Get file content by its unique ID. The endpoint response return with a
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

                let query = Object.keys(payload).map(key => key + '=' + encodeURIComponent(payload[key])).join('&');
                
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

                let query = Object.keys(payload).map(key => key + '=' + encodeURIComponent(payload[key])).join('&');
                
                return config.endpoint + path + ((query) ? '?' + query : '');
            },

            /**
             * Get File for View
             *
             * Get file content by its unique ID. This endpoint is similar to the download
             * method but returns with no  'Content-Disposition: attachment' header.
             *
             * @param {string} fileId
             * @param {string} as
             * @throws {Error}
             * @return {string}             
             */
            getFileView: function(fileId, as = '') {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/view'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                if(as) {
                    payload['as'] = as;
                }

                payload['project'] = config.project;

                let query = Object.keys(payload).map(key => key + '=' + encodeURIComponent(payload[key])).join('&');
                
                return config.endpoint + path + ((query) ? '?' + query : '');
            }
        };

        let teams = {

            /**
             * List Teams
             *
             * Get a list of all the current user teams. You can use the query params to
             * filter your results. On admin mode, this endpoint will return a list of all
             * of the project teams. [Learn more about different API modes](/docs/admin).
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
             * Get team by its unique ID. All team members have read access for this
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
             * Update team by its unique ID. Only team owners have write access for this
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
             * Delete team by its unique ID. Only team owners have write access for this
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
             * Get team members by the team unique ID. All team members have read access
             * for this list of resources.
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             
             */
            getMemberships: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}/memberships'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

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
             * Status](/docs/teams#updateMembershipStatus) endpoint to allow the user to
             * accept the invitation to the team.
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
             * delete a user membership even if he didn't accept it.
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
             * after he is being redirected back to your app from the invitation email he
             * was sent.
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

        return {
            setEndpoint: setEndpoint,
            setProject: setProject,
            setKey: setKey,
            setLocale: setLocale,
            setMode: setMode,
            account: account,
            avatars: avatars,
            database: database,
            locale: locale,
            storage: storage,
            teams: teams
        };
    };

    if(typeof module !== "undefined") {
        module.exports = window.Appwrite;
    }

})((typeof window !== "undefined") ? window : {});