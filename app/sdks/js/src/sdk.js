(function (window) {
    window.Appwrite = function () {

        let config = {
            endpoint: 'https://appwrite.test/v1',
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
                    if (params.hasOwnProperty(p)) {
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

            addGlobalHeader('x-sdk-version', 'appwrite:javascript:v1.0.14');
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
                            path = addParam(path, key, params[key]);
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
             * Get currently logged in user data as JSON object.
             *
             * @throws {Error}
             * @return {Promise}             */
            get: function() {
                let path = '/account';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Delete Account
             *
             * Delete currently logged in user account.
             *
             * @throws {Error}
             * @return {Promise}             */
            delete: function() {
                let path = '/account';

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
            updateEmail: function(email, password) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/account/email';

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'email': email, 
                            'password': password
                        });
            },

            /**
             * Update Account Name
             *
             * Update currently logged in user account name.
             *
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             */
            updateName: function(name) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/account/name';

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'name': name
                        });
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
             * @return {Promise}             */
            updatePassword: function(password, oldPassword) {
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                if(oldPassword === undefined) {
                    throw new Error('Missing required parameter: "oldPassword"');
                }
                
                let path = '/account/password';

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'password': password, 
                            'old-password': oldPassword
                        });
            },

            /**
             * Get Account Preferences
             *
             * Get currently logged in user preferences key-value object.
             *
             * @throws {Error}
             * @return {Promise}             */
            getPrefs: function() {
                let path = '/account/prefs';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Update Account Prefs
             *
             * Update currently logged in user account preferences. You can pass only the
             * specific settings you wish to update.
             *
             * @param {string} prefs
             * @throws {Error}
             * @return {Promise}             */
            updatePrefs: function(prefs) {
                if(prefs === undefined) {
                    throw new Error('Missing required parameter: "prefs"');
                }
                
                let path = '/account/prefs';

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'prefs': prefs
                        });
            },

            /**
             * Get Account Security Log
             *
             * Get currently logged in user list of latest security activity logs. Each
             * log returns user IP address, location and date and time of log.
             *
             * @throws {Error}
             * @return {Promise}             */
            getSecurity: function() {
                let path = '/account/security';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Get Account Active Sessions
             *
             * Get currently logged in user list of active sessions across different
             * devices.
             *
             * @throws {Error}
             * @return {Promise}             */
            getSessions: function() {
                let path = '/account/sessions';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            }
        };

        let auth = {

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
             * @param {string} email
             * @param {string} password
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {null}             */
            login: function(email, password, success = '', failure = '') {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/auth/login';

                return iframe('post', path, {project: config.project,
                    'email': email, 
                    'password': password, 
                    'success': success, 
                    'failure': failure
                });
            },

            /**
             * Logout Current Session
             *
             * Use this endpoint to log out the currently logged in user from his account.
             * When succeed this endpoint will delete the user session and remove the
             * session secret cookie.
             *
             * @throws {Error}
             * @return {Promise}             */
            logout: function() {
                let path = '/auth/logout';

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Logout Specific Session
             *
             * Use this endpoint to log out the currently logged in user from all his
             * account sessions across all his different devices. When using the option id
             * argument, only the session unique ID provider will be deleted.
             *
             * @param {string} id
             * @throws {Error}
             * @return {Promise}             */
            logoutBySession: function(id) {
                if(id === undefined) {
                    throw new Error('Missing required parameter: "id"');
                }
                
                let path = '/auth/logout/{id}'.replace(new RegExp('{id}', 'g'), id);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

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
             * @param {string} email
             * @param {string} redirect
             * @throws {Error}
             * @return {Promise}             */
            recovery: function(email, redirect) {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(redirect === undefined) {
                    throw new Error('Missing required parameter: "redirect"');
                }
                
                let path = '/auth/recovery';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'email': email, 
                            'redirect': redirect
                        });
            },

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
             * @param {string} userId
             * @param {string} token
             * @param {string} passwordA
             * @param {string} passwordB
             * @throws {Error}
             * @return {Promise}             */
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

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'userId': userId, 
                            'token': token, 
                            'password-a': passwordA, 
                            'password-b': passwordB
                        });
            },

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
             * @param {string} email
             * @param {string} password
             * @param {string} redirect
             * @param {string} name
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {null}             */
            register: function(email, password, redirect, name = '', success = '', failure = '') {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                if(redirect === undefined) {
                    throw new Error('Missing required parameter: "redirect"');
                }
                
                let path = '/auth/register';

                return iframe('post', path, {project: config.project,
                    'email': email, 
                    'password': password, 
                    'name': name, 
                    'redirect': redirect, 
                    'success': success, 
                    'failure': failure
                });
            },

            /**
             * Confirm User
             *
             * Use this endpoint to complete the confirmation of the user account email
             * address. Both the **userId** and **token** arguments will be passed as
             * query parameters to the redirect URL you have provided when sending your
             * request to the /auth/register endpoint.
             *
             * @param {string} userId
             * @param {string} token
             * @throws {Error}
             * @return {Promise}             */
            confirm: function(userId, token) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(token === undefined) {
                    throw new Error('Missing required parameter: "token"');
                }
                
                let path = '/auth/register/confirm';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'userId': userId, 
                            'token': token
                        });
            },

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
             * @param {string} redirect
             * @throws {Error}
             * @return {Promise}             */
            confirmResend: function(redirect) {
                if(redirect === undefined) {
                    throw new Error('Missing required parameter: "redirect"');
                }
                
                let path = '/auth/register/confirm/resend';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'redirect': redirect
                        });
            },

            /**
             * OAuth Callback
             *
             *
             * @param {string} projectId
             * @param {string} provider
             * @param {string} code
             * @param {string} state
             * @throws {Error}
             * @return {Promise}             */
            oauthCallback: function(projectId, provider, code, state = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/oauth/callback/{provider}/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{provider}', 'g'), provider);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'code': code, 
                            'state': state
                        });
            },

            /**
             * OAuth Login
             *
             *
             * @param {string} provider
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {Promise}             */
            oauth: function(provider, success = '', failure = '') {
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                let path = '/oauth/{provider}'.replace(new RegExp('{provider}', 'g'), provider);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'success': success, 
                            'failure': failure
                        });
            }
        };

        let avatars = {

            /**
             * Get Browser Icon
             *
             * You can use this endpoint to show different browser icons to your users,
             * The code argument receives the browser code as appear in your user
             * /account/sessions endpoint. Use width, height and quality arguments to
             * change the output settings.
             *
             * @param {string} code
             * @param {number} width
             * @param {number} height
             * @param {number} quality
             * @throws {Error}
             * @return {Promise}             */
            getBrowser: function(code, width = 100, height = 100, quality = 100) {
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/avatars/browsers/{code}'.replace(new RegExp('{code}', 'g'), code);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'width': width, 
                            'height': height, 
                            'quality': quality
                        });
            },

            /**
             * Get Credit Card Icon
             *
             * Need to display your users with your billing method or there payment
             * methods? The credit card endpoint will return you the icon of the credit
             * card provider you need. Use width, height and quality arguments to change
             * the output settings.
             *
             * @param {string} code
             * @param {number} width
             * @param {number} height
             * @param {number} quality
             * @throws {Error}
             * @return {Promise}             */
            getCreditCard: function(code, width = 100, height = 100, quality = 100) {
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/avatars/credit-cards/{code}'.replace(new RegExp('{code}', 'g'), code);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'width': width, 
                            'height': height, 
                            'quality': quality
                        });
            },

            /**
             * Get Favicon
             *
             * Use this endpoint to fetch the favorite icon (AKA favicon) of a  any remote
             * website URL.
             *
             * @param {string} url
             * @throws {Error}
             * @return {Promise}             */
            getFavicon: function(url) {
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/avatars/favicon';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'url': url
                        });
            },

            /**
             * Get Country Flag
             *
             * You can use this endpoint to show different country flags icons to your
             * users, The code argument receives the a 2 letter country code. Use width,
             * height and quality arguments to change the output settings.
             *
             * @param {string} code
             * @param {number} width
             * @param {number} height
             * @param {number} quality
             * @throws {Error}
             * @return {Promise}             */
            getFlag: function(code, width = 100, height = 100, quality = 100) {
                if(code === undefined) {
                    throw new Error('Missing required parameter: "code"');
                }
                
                let path = '/avatars/flags/{code}'.replace(new RegExp('{code}', 'g'), code);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'width': width, 
                            'height': height, 
                            'quality': quality
                        });
            },

            /**
             * Get image from and HTTP URL and crop to any size.
             *
             * Use this endpoint to fetch a remote image URL and crop it to any image size
             * you want. This endpoint is very useful if you need to crop a remote image
             * or in cases, you want to make sure a 3rd party image is properly served
             * using a TLS protocol.
             *
             * @param {string} url
             * @param {number} width
             * @param {number} height
             * @throws {Error}
             * @return {Promise}             */
            getImage: function(url, width = 400, height = 400) {
                if(url === undefined) {
                    throw new Error('Missing required parameter: "url"');
                }
                
                let path = '/avatars/image';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'url': url, 
                            'width': width, 
                            'height': height
                        });
            },

            /**
             * Text to QR Generator
             *
             * Converts a given plain text to a QR code image. You can use the query
             * parameters to change the size and style of the resulting image.
             *
             * @param {string} text
             * @param {number} size
             * @param {number} margin
             * @param {number} download
             * @throws {Error}
             * @return {Promise}             */
            getQR: function(text, size = 400, margin = 1, download = 0) {
                if(text === undefined) {
                    throw new Error('Missing required parameter: "text"');
                }
                
                let path = '/avatars/qr';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'text': text, 
                            'size': size, 
                            'margin': margin, 
                            'download': download
                        });
            }
        };

        let database = {

            /**
             * List Collections
             *
             * Get a list of all the user collections. You can use the query params to
             * filter your results. On admin mode, this endpoint will return a list of all
             * of the project collections. [Learn more about different API
             * modes](/docs/modes).
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             */
            listCollections: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/database';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'search': search, 
                            'limit': limit, 
                            'offset': offset, 
                            'orderType': orderType
                        });
            },

            /**
             * Create Collection
             *
             * Create a new Collection.
             *
             * @param {string} name
             * @param {array} read
             * @param {array} write
             * @param {array} rules
             * @throws {Error}
             * @return {Promise}             */
            createCollection: function(name, read = [], write = [], rules = []) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/database';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'read': read, 
                            'write': write, 
                            'rules': rules
                        });
            },

            /**
             * Get Collection
             *
             * Get collection by its unique ID. This endpoint response returns a JSON
             * object with the collection metadata.
             *
             * @param {string} collectionId
             * @throws {Error}
             * @return {Promise}             */
            getCollection: function(collectionId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Update Collection
             *
             * Update collection by its unique ID.
             *
             * @param {string} collectionId
             * @param {string} name
             * @param {array} read
             * @param {array} write
             * @param {array} rules
             * @throws {Error}
             * @return {Promise}             */
            updateCollection: function(collectionId, name, read = [], write = [], rules = []) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'read': read, 
                            'write': write, 
                            'rules': rules
                        });
            },

            /**
             * Delete Collection
             *
             * Delete a collection by its unique ID. Only users with write permissions
             * have access to delete this resource.
             *
             * @param {string} collectionId
             * @throws {Error}
             * @return {Promise}             */
            deleteCollection: function(collectionId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/{collectionId}'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List Documents
             *
             * Get a list of all the user documents. You can use the query params to
             * filter your results. On admin mode, this endpoint will return a list of all
             * of the project documents. [Learn more about different API
             * modes](/docs/modes).
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
             * @return {Promise}             */
            listDocuments: function(collectionId, filters = [], offset = 0, limit = 50, orderField = '$uid', orderType = 'ASC', orderCast = 'string', search = '', first = 0, last = 0) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                let path = '/database/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'filters': filters, 
                            'offset': offset, 
                            'limit': limit, 
                            'order-field': orderField, 
                            'order-type': orderType, 
                            'order-cast': orderCast, 
                            'search': search, 
                            'first': first, 
                            'last': last
                        });
            },

            /**
             * Create Document
             *
             * Create a new Document.
             *
             * @param {string} collectionId
             * @param {string} data
             * @param {array} read
             * @param {array} write
             * @param {string} parentDocument
             * @param {string} parentProperty
             * @param {string} parentPropertyType
             * @throws {Error}
             * @return {Promise}             */
            createDocument: function(collectionId, data, read = [], write = [], parentDocument = '', parentProperty = '', parentPropertyType = 'assign') {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(data === undefined) {
                    throw new Error('Missing required parameter: "data"');
                }
                
                let path = '/database/{collectionId}/documents'.replace(new RegExp('{collectionId}', 'g'), collectionId);

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'data': data, 
                            'read': read, 
                            'write': write, 
                            'parentDocument': parentDocument, 
                            'parentProperty': parentProperty, 
                            'parentPropertyType': parentPropertyType
                        });
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
             * @return {Promise}             */
            getDocument: function(collectionId, documentId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(documentId === undefined) {
                    throw new Error('Missing required parameter: "documentId"');
                }
                
                let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Update Document
             *
             *
             * @param {string} collectionId
             * @param {string} documentId
             * @param {string} data
             * @param {array} read
             * @param {array} write
             * @throws {Error}
             * @return {Promise}             */
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

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'data': data, 
                            'read': read, 
                            'write': write
                        });
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
             * @return {Promise}             */
            deleteDocument: function(collectionId, documentId) {
                if(collectionId === undefined) {
                    throw new Error('Missing required parameter: "collectionId"');
                }
                
                if(documentId === undefined) {
                    throw new Error('Missing required parameter: "documentId"');
                }
                
                let path = '/database/{collectionId}/documents/{documentId}'.replace(new RegExp('{collectionId}', 'g'), collectionId).replace(new RegExp('{documentId}', 'g'), documentId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            }
        };

        let locale = {

            /**
             * Get User Locale
             *
             * Get the current user location based on IP. Returns an object with user
             * country code, country name, continent name, continent code, ip address and
             * suggested currency. You can use the locale header to get the data in
             * supported language.
             *
             * @throws {Error}
             * @return {Promise}             */
            getLocale: function() {
                let path = '/locale';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List Countries
             *
             * List of all countries. You can use the locale header to get the data in
             * supported language.
             *
             * @throws {Error}
             * @return {Promise}             */
            getCountries: function() {
                let path = '/locale/countries';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List EU Countries
             *
             * List of all countries that are currently members of the EU. You can use the
             * locale header to get the data in supported language. UK brexit date is
             * currently set to 2019-10-31 and will be updated if and when needed.
             *
             * @throws {Error}
             * @return {Promise}             */
            getCountriesEU: function() {
                let path = '/locale/countries/eu';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List Countries Phone Codes
             *
             * List of all countries phone codes. You can use the locale header to get the
             * data in supported language.
             *
             * @throws {Error}
             * @return {Promise}             */
            getCountriesPhones: function() {
                let path = '/locale/countries/phones';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List of currencies
             *
             * List of all currencies, including currency symol, name, plural, and decimal
             * digits for all major and minor currencies. You can use the locale header to
             * get the data in supported language.
             *
             * @throws {Error}
             * @return {Promise}             */
            getCurrencies: function() {
                let path = '/locale/currencies';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            }
        };

        let projects = {

            /**
             * List Projects
             *
             *
             * @throws {Error}
             * @return {Promise}             */
            listProjects: function() {
                let path = '/projects';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @param {array} clients
             * @param {string} legalName
             * @param {string} legalCountry
             * @param {string} legalState
             * @param {string} legalCity
             * @param {string} legalAddress
             * @param {string} legalTaxId
             * @throws {Error}
             * @return {Promise}             */
            createProject: function(name, teamId, description = '', logo = '', url = '', clients = [], legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/projects';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'teamId': teamId, 
                            'description': description, 
                            'logo': logo, 
                            'url': url, 
                            'clients': clients, 
                            'legalName': legalName, 
                            'legalCountry': legalCountry, 
                            'legalState': legalState, 
                            'legalCity': legalCity, 
                            'legalAddress': legalAddress, 
                            'legalTaxId': legalTaxId
                        });
            },

            /**
             * Get Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            getProject: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @param {array} clients
             * @param {string} legalName
             * @param {string} legalCountry
             * @param {string} legalState
             * @param {string} legalCity
             * @param {string} legalAddress
             * @param {string} legalTaxId
             * @throws {Error}
             * @return {Promise}             */
            updateProject: function(projectId, name, description = '', logo = '', url = '', clients = [], legalName = '', legalCountry = '', legalState = '', legalCity = '', legalAddress = '', legalTaxId = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'description': description, 
                            'logo': logo, 
                            'url': url, 
                            'clients': clients, 
                            'legalName': legalName, 
                            'legalCountry': legalCountry, 
                            'legalState': legalState, 
                            'legalCity': legalCity, 
                            'legalAddress': legalAddress, 
                            'legalTaxId': legalTaxId
                        });
            },

            /**
             * Delete Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            deleteProject: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List Keys
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            listKeys: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/keys'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Create Key
             *
             *
             * @param {string} projectId
             * @param {string} name
             * @param {array} scopes
             * @throws {Error}
             * @return {Promise}             */
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

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'scopes': scopes
                        });
            },

            /**
             * Get Key
             *
             *
             * @param {string} projectId
             * @param {string} keyId
             * @throws {Error}
             * @return {Promise}             */
            getKey: function(projectId, keyId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(keyId === undefined) {
                    throw new Error('Missing required parameter: "keyId"');
                }
                
                let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'scopes': scopes
                        });
            },

            /**
             * Delete Key
             *
             *
             * @param {string} projectId
             * @param {string} keyId
             * @throws {Error}
             * @return {Promise}             */
            deleteKey: function(projectId, keyId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(keyId === undefined) {
                    throw new Error('Missing required parameter: "keyId"');
                }
                
                let path = '/projects/{projectId}/keys/{keyId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{keyId}', 'g'), keyId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
            updateProjectOAuth: function(projectId, provider, appId = '', secret = '') {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(provider === undefined) {
                    throw new Error('Missing required parameter: "provider"');
                }
                
                let path = '/projects/{projectId}/oauth'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'provider': provider, 
                            'appId': appId, 
                            'secret': secret
                        });
            },

            /**
             * List Platforms
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            listPlatforms: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/platforms'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'type': type, 
                            'name': name, 
                            'key': key, 
                            'store': store, 
                            'url': url
                        });
            },

            /**
             * Get Platform
             *
             *
             * @param {string} projectId
             * @param {string} platformId
             * @throws {Error}
             * @return {Promise}             */
            getPlatform: function(projectId, platformId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(platformId === undefined) {
                    throw new Error('Missing required parameter: "platformId"');
                }
                
                let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'key': key, 
                            'store': store, 
                            'url': url
                        });
            },

            /**
             * Delete Platform
             *
             *
             * @param {string} projectId
             * @param {string} platformId
             * @throws {Error}
             * @return {Promise}             */
            deletePlatform: function(projectId, platformId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(platformId === undefined) {
                    throw new Error('Missing required parameter: "platformId"');
                }
                
                let path = '/projects/{projectId}/platforms/{platformId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{platformId}', 'g'), platformId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List Tasks
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            listTasks: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/tasks'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'status': status, 
                            'schedule': schedule, 
                            'security': security, 
                            'httpMethod': httpMethod, 
                            'httpUrl': httpUrl, 
                            'httpHeaders': httpHeaders, 
                            'httpUser': httpUser, 
                            'httpPass': httpPass
                        });
            },

            /**
             * Get Task
             *
             *
             * @param {string} projectId
             * @param {string} taskId
             * @throws {Error}
             * @return {Promise}             */
            getTask: function(projectId, taskId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(taskId === undefined) {
                    throw new Error('Missing required parameter: "taskId"');
                }
                
                let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'status': status, 
                            'schedule': schedule, 
                            'security': security, 
                            'httpMethod': httpMethod, 
                            'httpUrl': httpUrl, 
                            'httpHeaders': httpHeaders, 
                            'httpUser': httpUser, 
                            'httpPass': httpPass
                        });
            },

            /**
             * Delete Task
             *
             *
             * @param {string} projectId
             * @param {string} taskId
             * @throws {Error}
             * @return {Promise}             */
            deleteTask: function(projectId, taskId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(taskId === undefined) {
                    throw new Error('Missing required parameter: "taskId"');
                }
                
                let path = '/projects/{projectId}/tasks/{taskId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{taskId}', 'g'), taskId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Get Project
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            getProjectUsage: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/usage'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * List Webhooks
             *
             *
             * @param {string} projectId
             * @throws {Error}
             * @return {Promise}             */
            listWebhooks: function(projectId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                let path = '/projects/{projectId}/webhooks'.replace(new RegExp('{projectId}', 'g'), projectId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'events': events, 
                            'url': url, 
                            'security': security, 
                            'httpUser': httpUser, 
                            'httpPass': httpPass
                        });
            },

            /**
             * Get Webhook
             *
             *
             * @param {string} projectId
             * @param {string} webhookId
             * @throws {Error}
             * @return {Promise}             */
            getWebhook: function(projectId, webhookId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(webhookId === undefined) {
                    throw new Error('Missing required parameter: "webhookId"');
                }
                
                let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
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

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'events': events, 
                            'url': url, 
                            'security': security, 
                            'httpUser': httpUser, 
                            'httpPass': httpPass
                        });
            },

            /**
             * Delete Webhook
             *
             *
             * @param {string} projectId
             * @param {string} webhookId
             * @throws {Error}
             * @return {Promise}             */
            deleteWebhook: function(projectId, webhookId) {
                if(projectId === undefined) {
                    throw new Error('Missing required parameter: "projectId"');
                }
                
                if(webhookId === undefined) {
                    throw new Error('Missing required parameter: "webhookId"');
                }
                
                let path = '/projects/{projectId}/webhooks/{webhookId}'.replace(new RegExp('{projectId}', 'g'), projectId).replace(new RegExp('{webhookId}', 'g'), webhookId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            }
        };

        let storage = {

            /**
             * List Files
             *
             * Get a list of all the user files. You can use the query params to filter
             * your results. On admin mode, this endpoint will return a list of all of the
             * project files. [Learn more about different API modes](/docs/modes).
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             */
            listFiles: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/storage/files';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'search': search, 
                            'limit': limit, 
                            'offset': offset, 
                            'orderType': orderType
                        });
            },

            /**
             * Create File
             *
             * Create a new file. The user who creates the file will automatically be
             * assigned to read and write access unless he has passed custom values for
             * read and write arguments.
             *
             * @param {File} files
             * @param {array} read
             * @param {array} write
             * @param {string} folderId
             * @throws {Error}
             * @return {Promise}             */
            createFile: function(files, read = [], write = [], folderId = '') {
                if(files === undefined) {
                    throw new Error('Missing required parameter: "files"');
                }
                
                let path = '/storage/files';

                return http
                    .post(path, {'content-type': 'multipart/form-data'},
                        {
                            'files': files, 
                            'read': read, 
                            'write': write, 
                            'folderId': folderId
                        });
            },

            /**
             * Get File
             *
             * Get file by its unique ID. This endpoint response returns a JSON object
             * with the file metadata.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {Promise}             */
            getFile: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Delete File
             *
             * Delete a file by its unique ID. Only users with write permissions have
             * access to delete this resource.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {Promise}             */
            deleteFile: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}'.replace(new RegExp('{fileId}', 'g'), fileId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Download File
             *
             * Get file content by its unique ID. The endpoint response return with a
             * 'Content-Disposition: attachment' header that tells the browser to start
             * downloading the file to user downloads directory.
             *
             * @param {string} fileId
             * @throws {Error}
             * @return {Promise}             */
            getFileDownload: function(fileId) {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/download'.replace(new RegExp('{fileId}', 'g'), fileId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Preview File
             *
             * Get file preview image. Currently, this method supports preview for image
             * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
             * and spreadsheets will return file icon image. You can also pass query
             * string arguments for cutting and resizing your preview image.
             *
             * @param {string} fileId
             * @param {number} width
             * @param {number} height
             * @param {number} quality
             * @param {string} background
             * @param {string} output
             * @throws {Error}
             * @return {Promise}             */
            getFilePreview: function(fileId, width = 0, height = 0, quality = 100, background = '', output = '') {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/preview'.replace(new RegExp('{fileId}', 'g'), fileId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'width': width, 
                            'height': height, 
                            'quality': quality, 
                            'background': background, 
                            'output': output
                        });
            },

            /**
             * View File
             *
             * Get file content by its unique ID. This endpoint is similar to the download
             * method but returns with no  'Content-Disposition: attachment' header.
             *
             * @param {string} fileId
             * @param {string} as
             * @throws {Error}
             * @return {Promise}             */
            getFileView: function(fileId, as = '') {
                if(fileId === undefined) {
                    throw new Error('Missing required parameter: "fileId"');
                }
                
                let path = '/storage/files/{fileId}/view'.replace(new RegExp('{fileId}', 'g'), fileId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'as': as
                        });
            }
        };

        let teams = {

            /**
             * List Teams
             *
             * Get a list of all the current user teams. You can use the query params to
             * filter your results. On admin mode, this endpoint will return a list of all
             * of the project teams. [Learn more about different API modes](/docs/modes).
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             */
            listTeams: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/teams';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'search': search, 
                            'limit': limit, 
                            'offset': offset, 
                            'orderType': orderType
                        });
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
             * @param {array} roles
             * @throws {Error}
             * @return {Promise}             */
            createTeam: function(name, roles = ["owner"]) {
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/teams';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'name': name, 
                            'roles': roles
                        });
            },

            /**
             * Get Team
             *
             * Get team by its unique ID. All team members have read access for this
             * resource.
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             */
            getTeam: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
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
             * @return {Promise}             */
            updateTeam: function(teamId, name) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(name === undefined) {
                    throw new Error('Missing required parameter: "name"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                return http
                    .put(path, {'content-type': 'application/json'},
                        {
                            'name': name
                        });
            },

            /**
             * Delete Team
             *
             * Delete team by its unique ID. Only team owners have write access for this
             * resource.
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             */
            deleteTeam: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}'.replace(new RegExp('{teamId}', 'g'), teamId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Get Team Members
             *
             * Get team members by the team unique ID. All team members have read access
             * for this list of resources.
             *
             * @param {string} teamId
             * @throws {Error}
             * @return {Promise}             */
            getTeamMembers: function(teamId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                let path = '/teams/{teamId}/members'.replace(new RegExp('{teamId}', 'g'), teamId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Create Team Membership
             *
             * Use this endpoint to invite a new member to your team. An email with a link
             * to join the team will be sent to the new member email address. If member
             * doesn't exists in the project it will be automatically created.
             * 
             * Use the redirect parameter to redirect the user from the invitation email
             * back to your app. When the user is redirected, use the
             * /teams/{teamId}/memberships/{inviteId}/status endpoint to finally join the
             * user to the team.
             * 
             * Please notice that in order to avoid a [Redirect
             * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
             * the only valid redirect URL's are the once from domains you have set when
             * added your platforms in the console interface.
             *
             * @param {string} teamId
             * @param {string} email
             * @param {array} roles
             * @param {string} redirect
             * @param {string} name
             * @throws {Error}
             * @return {Promise}             */
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

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'email': email, 
                            'name': name, 
                            'roles': roles, 
                            'redirect': redirect
                        });
            },

            /**
             * Delete Team Membership
             *
             * This endpoint allows a user to leave a team or for a team owner to delete
             * the membership of any other team member.
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @throws {Error}
             * @return {Promise}             */
            deleteTeamMembership: function(teamId, inviteId) {
                if(teamId === undefined) {
                    throw new Error('Missing required parameter: "teamId"');
                }
                
                if(inviteId === undefined) {
                    throw new Error('Missing required parameter: "inviteId"');
                }
                
                let path = '/teams/{teamId}/memberships/{inviteId}'.replace(new RegExp('{teamId}', 'g'), teamId).replace(new RegExp('{inviteId}', 'g'), inviteId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Create Team Membership (Resend Invitation Email)
             *
             * Use this endpoint to resend your invitation email for a user to join a
             * team.
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @param {string} redirect
             * @throws {Error}
             * @return {Promise}             */
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

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'redirect': redirect
                        });
            },

            /**
             * Update Team Membership Status
             *
             * Use this endpoint to let user accept an invitation to join a team after he
             * is being redirect back to your app from the invitation email. Use the
             * success and failure URL's to redirect users back to your application after
             * the request completes.
             * 
             * Please notice that in order to avoid a [Redirect
             * Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md)
             * the only valid redirect URL's are the once from domains you have set when
             * added your platforms in the console interface.
             * 
             * When not using the success or failure redirect arguments this endpoint will
             * result with a 200 status code on success and with 401 status error on
             * failure. This behavior was applied to help the web clients deal with
             * browsers who don't allow to set 3rd party HTTP cookies needed for saving
             * the account session token.
             *
             * @param {string} teamId
             * @param {string} inviteId
             * @param {string} userId
             * @param {string} secret
             * @param {string} success
             * @param {string} failure
             * @throws {Error}
             * @return {null}             */
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

                return iframe('patch', path, {project: config.project,
                    'userId': userId, 
                    'secret': secret, 
                    'success': success, 
                    'failure': failure
                });
            }
        };

        let users = {

            /**
             * List Users
             *
             * Get a list of all the project users. You can use the query params to filter
             * your results.
             *
             * @param {string} search
             * @param {number} limit
             * @param {number} offset
             * @param {string} orderType
             * @throws {Error}
             * @return {Promise}             */
            listUsers: function(search = '', limit = 25, offset = 0, orderType = 'ASC') {
                let path = '/users';

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                            'search': search, 
                            'limit': limit, 
                            'offset': offset, 
                            'orderType': orderType
                        });
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
             * @return {Promise}             */
            createUser: function(email, password, name = '') {
                if(email === undefined) {
                    throw new Error('Missing required parameter: "email"');
                }
                
                if(password === undefined) {
                    throw new Error('Missing required parameter: "password"');
                }
                
                let path = '/users';

                return http
                    .post(path, {'content-type': 'application/json'},
                        {
                            'email': email, 
                            'password': password, 
                            'name': name
                        });
            },

            /**
             * Get User
             *
             * Get user by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             */
            getUser: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Get User Logs
             *
             * Get user activity logs list by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             */
            getUserLogs: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/logs'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Get User Prefs
             *
             * Get user preferences by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             */
            getUserPrefs: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/prefs'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Get User Sessions
             *
             * Get user sessions list by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             */
            getUserSessions: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .get(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Delete User Sessions
             *
             * Delete all user sessions by its unique ID.
             *
             * @param {string} userId
             * @throws {Error}
             * @return {Promise}             */
            deleteUserSessions: function(userId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                let path = '/users/{userId}/sessions'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                        });
            },

            /**
             * Delete User Session
             *
             * Delete user sessions by its unique ID.
             *
             * @param {string} userId
             * @param {string} sessionId
             * @throws {Error}
             * @return {Promise}             */
            deleteUsersSession: function(userId, sessionId) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(sessionId === undefined) {
                    throw new Error('Missing required parameter: "sessionId"');
                }
                
                let path = '/users/{userId}/sessions/:session'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .delete(path, {'content-type': 'application/json'},
                        {
                            'sessionId': sessionId
                        });
            },

            /**
             * Update user status
             *
             * Update user status by its unique ID.
             *
             * @param {string} userId
             * @param {string} status
             * @throws {Error}
             * @return {Promise}             */
            updateUserStatus: function(userId, status) {
                if(userId === undefined) {
                    throw new Error('Missing required parameter: "userId"');
                }
                
                if(status === undefined) {
                    throw new Error('Missing required parameter: "status"');
                }
                
                let path = '/users/{userId}/status'.replace(new RegExp('{userId}', 'g'), userId);

                return http
                    .patch(path, {'content-type': 'application/json'},
                        {
                            'status': status
                        });
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

})(window);