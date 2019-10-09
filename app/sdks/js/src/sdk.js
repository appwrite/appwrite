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
         * Your Appwrite project ID. You can find your project ID in your Appwrite
\         * console project settings.
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
         * Your Appwrite project secret key. You can can create a new API key from
\         * your Appwrite console API keys dashboard.
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

            addGlobalHeader('x-sdk-version', 'appwrite:javascript:1.0.22');
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

        let iframe = function(method, url, params) {
            let form = document.createElement('form');

            form.setAttribute('method', method);
            form.setAttribute('action', config.endpoint + url);

            for(let key in params) {
                if(params.hasOwnProperty(key)) {
                    let hiddenField = document.createElement("input");
                    hiddenField.setAttribute("type", "hidden");
                    hiddenField.setAttribute("name", key);
                    hiddenField.setAttribute("value", params[key]);

                    form.appendChild(hiddenField);
                }
            }

            document.body.appendChild(form);

            return form.submit();
        };

        let account = {

            /**
             * Get Account
             *
             * /docs/references/account/get.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            get: function() {
                let path = '/account';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete Account
             *
             * /docs/references/account/delete.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            delete: function() {
                let path = '/account';

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Account Email
             *
             * /docs/references/account/update-email.md
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Account Name
             *
             * /docs/references/account/update-name.md
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Account Password
             *
             * /docs/references/account/update-password.md
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Account Preferences
             *
             * /docs/references/account/get-prefs.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getPrefs: function() {
                let path = '/account/prefs';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Account Prefs
             *
             * /docs/references/account/update-prefs.md
             *
             * @param {string} prefs
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Account Security Log
             *
             * /docs/references/account/get-security.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getSecurity: function() {
                let path = '/account/security';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Account Active Sessions
             *
             * /docs/references/account/get-sessions.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getSessions: function() {
                let path = '/account/sessions';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            }
        };

        let auth = {

            /**
             * Login User
             *
             * /docs/references/auth/login.md
             *
             * @param {string} email
             * @param {string} password
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {null}             
             */
            login: function(email, password, success, failure) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                if(success === undefined) {
                    throw new Error('Missing required parameter: "success"');
                }
                
                if(failure === undefined) {
                    throw new Error('Missing required parameter: "failure"');
                }
                
                let path = '/auth/login';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(password) {
                    payload['password'] = password;
                }

                if(success) {
                    payload['success'] = success;
                }

                if(failure) {
                    payload['failure'] = failure;
                }

                payload['project'] = config.project;

                return iframe('post', path, payload);
            },

            /**
             * Logout Current Session
             *
             * /docs/references/auth/logout.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            logout: function() {
                let path = '/auth/logout';

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Logout Specific Session
             *
             * /docs/references/auth/logout-by-session.md
             *
             * @param {string} id
             * @throws {Error}
             * @return {Promise}             
             */
            logoutBySession: function(id) {
                if(id === undefined) {
                    throw new Error('Missing required parameter: "id"');
                }
                
                let path = '/auth/logout/{id}'.replace(new RegExp('{id}', 'g'), id);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * OAuth Login
             *
             *
             * @param {string} provider
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {Promise}             
             */
            oauth: function(provider, success = '', failure = '') {
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                let path = '/auth/oauth/{provider}'.replace(new RegExp('{provider}', 'g'), provider);

                let payload = {};

                if(success) {
                    payload['success'] = success;
                }

                if(failure) {
                    payload['failure'] = failure;
                }

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Password Recovery
             *
             * /docs/references/auth/recovery.md
             *
             * @param {string} email
             * @param {string} reset
             * @throws {Error}
             * @return {Promise}             
             */
            recovery: function(email, reset) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(reset === undefined) {
                    throw new Error('Missing required parameter: "reset"');
                }
                
                let path = '/auth/recovery';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(reset) {
                    payload['reset'] = reset;
                }

                return http
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Password Reset
             *
             * /docs/references/auth/recovery-reset.md
             *
             * @param {string} userId
             * @param {string} token
             * @param {string} passwordA
             * @param {string} passwordB
             * @throws {Error}
             * @return {Promise}             
             */
            recoveryReset: function(userId, token, passwordA, passwordB) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(token === undefined) {
                    throw new Error('Missing required parameter: "token"');
                }
                
                if(passwordA === undefined) {
                    throw new Error('Missing required parameter: "passwordA"');
                }
                
                if(passwordB === undefined) {
                    throw new Error('Missing required parameter: "passwordB"');
                }
                
                let path = '/auth/recovery/reset';

                let payload = {};

                if(userId) {
                    payload['userId'] = userId;
                }

                if(token) {
                    payload['token'] = token;
                }

                if(passwordA) {
                    payload['password-a'] = passwordA;
                }

                if(passwordB) {
                    payload['password-b'] = passwordB;
                }

                return http
                    .put(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Register User
             *
             * /docs/references/auth/register.md
             *
             * @param {string} email
             * @param {string} password
             * @param {string} confirm
             * @param {string} success
             * @param {string} failure
             * @param {string} name
             * @throws {Error}
             * @return {null}             
             */
            register: function(email, password, confirm, success = '', failure = '', name = '') {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                if(confirm === undefined) {
                    throw new Error('Missing required parameter: "confirm"');
                }
                
                let path = '/auth/register';

                let payload = {};

                if(email) {
                    payload['email'] = email;
                }

                if(password) {
                    payload['password'] = password;
                }

                if(confirm) {
                    payload['confirm'] = confirm;
                }

                if(success) {
                    payload['success'] = success;
                }

                if(failure) {
                    payload['failure'] = failure;
                }

                if(name) {
                    payload['name'] = name;
                }

                payload['project'] = config.project;

                return iframe('post', path, payload);
            },

            /**
             * Confirm User
             *
             * /docs/references/auth/confirm.md
             *
             * @param {string} userId
             * @param {string} token
             * @throws {Error}
             * @return {Promise}             
             */
            confirm: function(userId, token) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(token === undefined) {
                    throw new Error('Missing required parameter: "token"');
                }
                
                let path = '/auth/register/confirm';

                let payload = {};

                if(userId) {
                    payload['userId'] = userId;
                }

                if(token) {
                    payload['token'] = token;
                }

                return http
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Resend Confirmation
             *
             * /docs/references/auth/confirm-resend.md
             *
             * @param {string} confirm
             * @throws {Error}
             * @return {Promise}             
             */
            confirmResend: function(confirm) {
                if(confirm === undefined) {
                    throw new Error('Missing required parameter: "confirm"');
                }
                
                let path = '/auth/register/confirm/resend';

                let payload = {};

                if(confirm) {
                    payload['confirm'] = confirm;
                }

                return http
                    .post(path, {'content-type': 'application/json'}, payload);
            }
        };

        let avatars = {

            /**
             * Get Browser Icon
             *
             * /docs/references/avatars/get-browser.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Credit Card Icon
             *
             * /docs/references/avatars/get-credit-cards.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Favicon
             *
             * /docs/references/avatars/get-favicon.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Country Flag
             *
             * /docs/references/avatars/get-flag.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Image from URL
             *
             * /docs/references/avatars/get-image.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Text to QR Generator
             *
             * /docs/references/avatars/get-qr.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            }
        };

        let database = {

            /**
             * List Collections
             *
             * /docs/references/database/list-collections.md
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             
             */
            listCollections: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/database';

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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Collection
             *
             * /docs/references/database/create-collection.md
             *
             * @param {string} name
             * @param {array} read
             * @param {array} write
             * @param {array} rules
             * @throws {Error}
             * @return {Promise}             
             */
            createCollection: function(name, read = [], write = [], rules = []) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/database';

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
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Collection
             *
             * /docs/references/database/get-collection.md
             *
             * @param {string} collectionId
             * @throws {Error}
             * @return {Promise}             
             */
            getCollection: function(collectionId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Collection
             *
             * /docs/references/database/update-collection.md
             *
             * @param {string} collectionId
             * @param {string} name
             * @param {array} read
             * @param {array} write
             * @param {array} rules
             * @throws {Error}
             * @return {Promise}             
             */
            updateCollection: function(collectionId, name, read = [], write = [], rules = []) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

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
                    .put(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete Collection
             *
             * /docs/references/database/delete-collection.md
             *
             * @param {string} collectionId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteCollection: function(collectionId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * List Documents
             *
             * /docs/references/database/list-documents.md
             *
             * @param {string} collectionId
             * @param {array} filters
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
            listDocuments: function(collectionId, filters = [], offset = 0, limit = 50, orderField = '$uid', orderType = 'ASC', orderCast = 'string', search = '', first = 0, last = 0) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Document
             *
             * /docs/references/database/create-document.md
             *
             * @param {string} collectionId
             * @param {string} data
             * @param {array} read
             * @param {array} write
             * @param {string} parentDocument
             * @param {string} parentProperty
             * @param {string} parentPropertyType
             * @throws {Error}
             * @return {Promise}             
             */
            createDocument: function(collectionId, data, read = [], write = [], parentDocument = '', parentProperty = '', parentPropertyType = 'assign') {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(data === undefined) {
                    throw new Error('Missing required parameter: "data"');
                }
                
                let path = '/database/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

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
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Document
             *
             * /docs/references/database/get-document.md
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
                
                let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Document
             *
             * /docs/references/database/update-document.md
             *
             * @param {string} collectionId
             * @param {string} documentId
             * @param {string} data
             * @param {array} read
             * @param {array} write
             * @throws {Error}
             * @return {Promise}             
             */
            updateDocument: function(collectionId, documentId, data, read = [], write = []) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(documentId === undefined) {
                    throw new Error('Missing required parameter: "documentId"');
                }
                
                if(data === undefined) {
                    throw new Error('Missing required parameter: "data"');
                }
                
                let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete Document
             *
             * /docs/references/database/delete-document.md
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
                
                let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            }
        };

        let locale = {

            /**
             * Get User Locale
             *
             * /docs/references/locale/get-locale.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getLocale: function() {
                let path = '/locale';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * List Countries
             *
             * /docs/references/locale/get-countires.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCountries: function() {
                let path = '/locale/countries';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * List EU Countries
             *
             * /docs/references/locale/get-countries-eu.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCountriesEU: function() {
                let path = '/locale/countries/eu';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * List Countries Phone Codes
             *
             * /docs/references/locale/get-countries-phones.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCountriesPhones: function() {
                let path = '/locale/countries/phones';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * List of currencies
             *
             * /docs/references/locale/get-currencies.md
             *
             * @throws {Error}
             * @return {Promise}             
             */
            getCurrencies: function() {
                let path = '/locale/currencies';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            }
        };

        let projects = {

            /**
             * List Projects
             *
             *
             * @throws {Error}
             * @return {Promise}             
             */
            listProjects: function() {
                let path = '/projects';

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
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
            createProject: function(name, teamId, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
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
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            getProject: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
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
            updateProject: function(projectId, name, description = '', logo = '', url = '', legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteProject: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Key
             *
             *
             * @param {string} projectId
             * @param {string} name
             * @param {array} scopes
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
                    .post(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Key
             *
             *
             * @param {string} projectId
             * @param {string} keyId
             * @param {string} name
             * @param {array} scopes
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
                    .put(path, {'content-type': 'application/json'}, payload);
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
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Project OAuth
             *
             *
             * @param {string} projectId
             * @param {string} provider
             * @param {string} appId
             * @param {string} secret
             * @throws {Error}
             * @return {Promise}             
             */
            updateProjectOAuth: function(projectId, provider, appId = '', secret = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                let path = '/projects/{projectId}/oauth'.replace(new RegExp('{projectId}', 'g'), projectId);

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
                    .patch(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
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
             * @param {string} url
             * @throws {Error}
             * @return {Promise}             
             */
            createPlatform: function(projectId, type, name, key = '', store = '', url = '') {
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

                if(url) {
                    payload['url'] = url;
                }

                return http
                    .post(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
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
             * @param {string} url
             * @throws {Error}
             * @return {Promise}             
             */
            updatePlatform: function(projectId, platformId, name, key = '', store = '', url = '') {
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

                if(url) {
                    payload['url'] = url;
                }

                return http
                    .put(path, {'content-type': 'application/json'}, payload);
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
                    .delete(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Task
             *
             *
             * @param {string} projectId
             * @param {string} name
             * @param {string} status
             * @param {string} schedule
             * @param {number} security
             * @param {string} httpMethod
             * @param {string} httpUrl
             * @param {array} httpHeaders
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
                    .post(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
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
             * @param {number} security
             * @param {string} httpMethod
             * @param {string} httpUrl
             * @param {array} httpHeaders
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
                    .put(path, {'content-type': 'application/json'}, payload);
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
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             
             */
            getProjectUsage: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/usage'.replace(new RegExp('{projectId}', 'g'), projectId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Webhook
             *
             *
             * @param {string} projectId
             * @param {string} name
             * @param {array} events
             * @param {string} url
             * @param {number} security
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
                    .post(path, {'content-type': 'application/json'}, payload);
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Webhook
             *
             *
             * @param {string} projectId
             * @param {string} webhookId
             * @param {string} name
             * @param {array} events
             * @param {string} url
             * @param {number} security
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
                    .put(path, {'content-type': 'application/json'}, payload);
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
                    .delete(path, {'content-type': 'application/json'}, payload);
            }
        };

        let storage = {

            /**
             * List Files
             *
             * /docs/references/storage/list-files.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create File
             *
             * /docs/references/storage/create-file.md
             *
             * @param {File} files
             * @param {array} read
             * @param {array} write
             * @param {string} folderId
             * @throws {Error}
             * @return {Promise}             
             */
            createFile: function(files, read = [], write = [], folderId = '') {
                if(files === undefined) {
                    throw new Error('Missing required parameter: "files"');
                }
                
                let path = '/storage/files';

                let payload = {};

                if(files) {
                    payload['files'] = files;
                }

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                if(folderId) {
                    payload['folderId'] = folderId;
                }

                return http
                    .post(path, {'content-type': 'multipart/form-data'}, payload);
            },

            /**
             * Get File
             *
             * /docs/references/storage/get-file.md
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update File
             *
             * /docs/references/storage/update-file.md
             *
             * @param {string} fileId
             * @param {array} read
             * @param {array} write
             * @param {string} folderId
             * @throws {Error}
             * @return {Promise}             
             */
            updateFile: function(fileId, read = [], write = [], folderId = '') {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                if(read) {
                    payload['read'] = read;
                }

                if(write) {
                    payload['write'] = write;
                }

                if(folderId) {
                    payload['folderId'] = folderId;
                }

                return http
                    .put(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete File
             *
             * /docs/references/storage/delete-file.md
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
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get File for Download
             *
             * /docs/references/storage/get-file-download.md
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {Promise}             
             */
            getFileDownload: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/download'.replace(new RegExp('{fileId}', 'g'), fileId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get File Preview
             *
             * /docs/references/storage/get-file-preview.md
             *
             * @param {string} fileId
             * @param {number} width
             * @param {number} height
             * @param {number} quality
             * @param {string} background
             * @param {string} output
             * @throws {Error}
             * @return {Promise}             
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

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get File for View
             *
             * /docs/references/storage/get-file-view.md
             *
             * @param {string} fileId
             * @param {string} as
             * @throws {Error}
             * @return {Promise}             
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

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            }
        };

        let teams = {

            /**
             * List Teams
             *
             * /docs/references/teams/list-teams.md
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             
             */
            listTeams: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Team
             *
             * /docs/references/teams/create-team.md
             *
             * @param {string} name
             * @param {array} roles
             * @throws {Error}
             * @return {Promise}             
             */
            createTeam: function(name, roles = ["owner"]) {
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
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Team
             *
             * /docs/references/teams/get-team.md
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             
             */
            getTeam: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Team
             *
             * /docs/references/teams/update-team.md
             *
             * @param {string} teamId
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            updateTeam: function(teamId, name) {
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
                    .put(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete Team
             *
             * /docs/references/teams/delete-team.md
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteTeam: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get Team Members
             *
             * /docs/references/teams/get-team-members.md
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             
             */
            getTeamMembers: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}/members'.replace(new RegExp('{teamId}', 'g'), teamId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Team Membership
             *
             * /docs/references/teams/create-team-membership.md
             *
             * @param {string} teamId
             * @param {string} email
             * @param {array} roles
             * @param {string} redirect
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            createTeamMembership: function(teamId, email, roles, redirect, name = '') {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(roles === undefined) {
                    throw new Error('Missing required parameter: "roles"');
                }
                
                if(redirect === undefined) {
                    throw new Error('Missing required parameter: "redirect"');
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

                if(redirect) {
                    payload['redirect'] = redirect;
                }

                return http
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete Team Membership
             *
             * /docs/references/teams/delete-team-membership.md
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteTeamMembership: function(teamId, inviteId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(inviteId === undefined) {
                    throw new Error('Missing required parameter: "inviteId"');
                }
                
                let path = '/teams/{teamId}/memberships/{inviteId}'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create Team Membership (Resend)
             *
             * /docs/references/teams/create-team-membership-resend.md
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @param {string} redirect
             * @throws {Error}
             * @return {Promise}             
             */
            createTeamMembershipResend: function(teamId, inviteId, redirect) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(inviteId === undefined) {
                    throw new Error('Missing required parameter: "inviteId"');
                }
                
                if(redirect === undefined) {
                    throw new Error('Missing required parameter: "redirect"');
                }
                
                let path = '/teams/{teamId}/memberships/{inviteId}/resend'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);

                let payload = {};

                if(redirect) {
                    payload['redirect'] = redirect;
                }

                return http
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Team Membership Status
             *
             * /docs/references/teams/update-team-membership-status.md
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @param {string} userId
             * @param {string} secret
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {null}             
             */
            updateTeamMembershipStatus: function(teamId, inviteId, userId, secret, success = '', failure = '') {
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

                if(success) {
                    payload['success'] = success;
                }

                if(failure) {
                    payload['failure'] = failure;
                }

                payload['project'] = config.project;

                return iframe('patch', path, payload);
            }
        };

        let users = {

            /**
             * List Users
             *
             * /docs/references/users/list-users.md
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             
             */
            listUsers: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
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
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Create User
             *
             * /docs/references/users/create-user.md
             *
             * @param {string} email
             * @param {string} password
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             
             */
            createUser: function(email, password, name = '') {
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
                    .post(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get User
             *
             * /docs/references/users/get-user.md
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getUser: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get User Logs
             *
             * /docs/references/users/get-user-logs.md
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getUserLogs: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/logs'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get User Prefs
             *
             * /docs/references/users/get-user-prefs.md
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getUserPrefs: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update Account Prefs
             *
             * /docs/references/users/update-user-prefs.md
             *
             * @param {string} userId
             * @param {string} prefs
             * @throws {Error}
             * @return {Promise}             
             */
            updateUserPrefs: function(userId, prefs) {
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Get User Sessions
             *
             * /docs/references/users/get-user-sessions.md
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            getUserSessions: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .get(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete User Sessions
             *
             * Delete all user sessions by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteUserSessions: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Delete User Session
             *
             * /docs/references/users/delete-user-session.md
             *
             * @param {string} userId
             * @param {string} sessionId
             * @throws {Error}
             * @return {Promise}             
             */
            deleteUserSession: function(userId, sessionId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(sessionId === undefined) {
                    throw new Error('Missing required parameter: "sessionId"');
                }
                
                let path = '/users/{userId}/sessions/:session'.replace(new RegExp('{userId}', 'g'), userId);

                let payload = {};

                if(sessionId) {
                    payload['sessionId'] = sessionId;
                }

                return http
                    .delete(path, {'content-type': 'application/json'}, payload);
            },

            /**
             * Update user status
             *
             * /docs/references/users/update-user-status.md
             *
             * @param {string} userId
             * @param {string} status
             * @throws {Error}
             * @return {Promise}             
             */
            updateUserStatus: function(userId, status) {
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
                    .patch(path, {'content-type': 'application/json'}, payload);
            }
        };

        return {
            setEndpoint: setEndpoint,
            setProject: setProject,
            setKey: setKey,
            setLocale: setLocale,
            setMode: setMode,
            account: account,
            auth: auth,
            avatars: avatars,
            database: database,
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