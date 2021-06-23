import "isomorphic-form-data";
declare type Headers = {
    [key: string]: string;
};
declare class AppwriteException extends Error {
    code: number;
    response: string;
    constructor(message: string, code?: number, response?: string);
}
declare class Appwrite {
    config: {
        endpoint: string;
        project: string;
        key: string;
        jwt: string;
        locale: string;
        mode: string;
    };
    headers: Headers;
    /**
     * Set Endpoint
     *
     * Your project endpoint
     *
     * @param {string} endpoint
     *
     * @returns {this}
     */
    setEndpoint(endpoint: string): this;
    /**
     * Set Project
     *
     * Your project ID
     *
     * @param value string
     *
     * @return {this}
     */
    setProject(value: string): this;
    /**
     * Set Key
     *
     * Your secret API key
     *
     * @param value string
     *
     * @return {this}
     */
    setKey(value: string): this;
    /**
     * Set JWT
     *
     * Your secret JSON Web Token
     *
     * @param value string
     *
     * @return {this}
     */
    setJWT(value: string): this;
    /**
     * Set Locale
     *
     * @param value string
     *
     * @return {this}
     */
    setLocale(value: string): this;
    /**
     * Set Mode
     *
     * @param value string
     *
     * @return {this}
     */
    setMode(value: string): this;
    private call;
    private flatten;
    account: {
        /**
         * Get Account
         *
         * Get currently logged in user data as JSON object.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        get: <T extends unknown>() => Promise<T>;
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
        create: <T_1 extends unknown>(email: string, password: string, name?: string | undefined) => Promise<T_1>;
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
        delete: <T_2 extends unknown>() => Promise<T_2>;
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
        updateEmail: <T_3 extends unknown>(email: string, password: string) => Promise<T_3>;
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
        createJWT: <T_4 extends unknown>() => Promise<T_4>;
        /**
         * Get Account Logs
         *
         * Get currently logged in user list of latest security activity logs. Each
         * log returns user IP address, location and date and time of log.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getLogs: <T_5 extends unknown>() => Promise<T_5>;
        /**
         * Update Account Name
         *
         * Update currently logged in user account name.
         *
         * @param {string} name
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        updateName: <T_6 extends unknown>(name: string) => Promise<T_6>;
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
        updatePassword: <T_7 extends unknown>(password: string, oldPassword?: string | undefined) => Promise<T_7>;
        /**
         * Get Account Preferences
         *
         * Get currently logged in user preferences as a key-value object.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getPrefs: <T_8 extends unknown>() => Promise<T_8>;
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
        updatePrefs: <T_9 extends unknown>(prefs: object) => Promise<T_9>;
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
        createRecovery: <T_10 extends unknown>(email: string, url: string) => Promise<T_10>;
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
        updateRecovery: <T_11 extends unknown>(userId: string, secret: string, password: string, passwordAgain: string) => Promise<T_11>;
        /**
         * Get Account Sessions
         *
         * Get currently logged in user list of active sessions across different
         * devices.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getSessions: <T_12 extends unknown>() => Promise<T_12>;
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
        createSession: <T_13 extends unknown>(email: string, password: string) => Promise<T_13>;
        /**
         * Delete All Account Sessions
         *
         * Delete all sessions from the user account and remove any sessions cookies
         * from the end client.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deleteSessions: <T_14 extends unknown>() => Promise<T_14>;
        /**
         * Create Anonymous Session
         *
         * Use this endpoint to allow a new user to register an anonymous account in
         * your project. This route will also create a new session for the user. To
         * allow the new user to convert an anonymous account to a normal account
         * account, you need to update its [email and
         * password](/docs/client/account#accountUpdateEmail).
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        createAnonymousSession: <T_15 extends unknown>() => Promise<T_15>;
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
         * @returns {void|string}
         */
        createOAuth2Session: (provider: string, success?: string | undefined, failure?: string | undefined, scopes?: string[] | undefined) => void | URL;
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
        deleteSession: <T_16 extends unknown>(sessionId: string) => Promise<T_16>;
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
        createVerification: <T_17 extends unknown>(url: string) => Promise<T_17>;
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
        updateVerification: <T_18 extends unknown>(userId: string, secret: string) => Promise<T_18>;
    };
    avatars: {
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
        getBrowser: (code: string, width?: number | undefined, height?: number | undefined, quality?: number | undefined) => URL;
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
        getCreditCard: (code: string, width?: number | undefined, height?: number | undefined, quality?: number | undefined) => URL;
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
        getFavicon: (url: string) => URL;
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
        getFlag: (code: string, width?: number | undefined, height?: number | undefined, quality?: number | undefined) => URL;
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
        getImage: (url: string, width?: number | undefined, height?: number | undefined) => URL;
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
        getInitials: (name?: string | undefined, width?: number | undefined, height?: number | undefined, color?: string | undefined, background?: string | undefined) => URL;
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
        getQR: (text: string, size?: number | undefined, margin?: number | undefined, download?: boolean | undefined) => URL;
    };
    database: {
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
        listCollections: <T extends unknown>(search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T>;
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
         * @returns {Promise}
         */
        createCollection: <T_1 extends unknown>(name: string, read: string[], write: string[], rules: string[]) => Promise<T_1>;
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
        getCollection: <T_2 extends unknown>(collectionId: string) => Promise<T_2>;
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
         * @returns {Promise}
         */
        updateCollection: <T_3 extends unknown>(collectionId: string, name: string, read?: string[] | undefined, write?: string[] | undefined, rules?: string[] | undefined) => Promise<T_3>;
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
        deleteCollection: <T_4 extends unknown>(collectionId: string) => Promise<T_4>;
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
         * @returns {Promise}
         */
        listDocuments: <T_5 extends unknown>(collectionId: string, filters?: string[] | undefined, limit?: number | undefined, offset?: number | undefined, orderField?: string | undefined, orderType?: string | undefined, orderCast?: string | undefined, search?: string | undefined) => Promise<T_5>;
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
         * @returns {Promise}
         */
        createDocument: <T_6 extends unknown>(collectionId: string, data: object, read?: string[] | undefined, write?: string[] | undefined, parentDocument?: string | undefined, parentProperty?: string | undefined, parentPropertyType?: string | undefined) => Promise<T_6>;
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
        getDocument: <T_7 extends unknown>(collectionId: string, documentId: string) => Promise<T_7>;
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
         * @returns {Promise}
         */
        updateDocument: <T_8 extends unknown>(collectionId: string, documentId: string, data: object, read?: string[] | undefined, write?: string[] | undefined) => Promise<T_8>;
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
        deleteDocument: <T_9 extends unknown>(collectionId: string, documentId: string) => Promise<T_9>;
    };
    functions: {
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
        list: <T extends unknown>(search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T>;
        /**
         * Create Function
         *
         * Create a new function. You can pass a list of
         * [permissions](/docs/permissions) to allow different project users or team
         * with access to execute the function using the client API.
         *
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
        create: <T_1 extends unknown>(name: string, execute: string[], runtime: string, vars?: object | undefined, events?: string[] | undefined, schedule?: string | undefined, timeout?: number | undefined) => Promise<T_1>;
        /**
         * Get Function
         *
         * Get a function by its unique ID.
         *
         * @param {string} functionId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        get: <T_2 extends unknown>(functionId: string) => Promise<T_2>;
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
        update: <T_3 extends unknown>(functionId: string, name: string, execute: string[], vars?: object | undefined, events?: string[] | undefined, schedule?: string | undefined, timeout?: number | undefined) => Promise<T_3>;
        /**
         * Delete Function
         *
         * Delete a function by its unique ID.
         *
         * @param {string} functionId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        delete: <T_4 extends unknown>(functionId: string) => Promise<T_4>;
        /**
         * List Executions
         *
         * Get a list of all the current user function execution logs. You can use the
         * query params to filter your results. On admin mode, this endpoint will
         * return a list of all of the project's executions. [Learn more about
         * different API modes](/docs/admin).
         *
         * @param {string} functionId
         * @param {string} search
         * @param {number} limit
         * @param {number} offset
         * @param {string} orderType
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        listExecutions: <T_5 extends unknown>(functionId: string, search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T_5>;
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
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        createExecution: <T_6 extends unknown>(functionId: string, data?: string | undefined) => Promise<T_6>;
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
        getExecution: <T_7 extends unknown>(functionId: string, executionId: string) => Promise<T_7>;
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
        updateTag: <T_8 extends unknown>(functionId: string, tag: string) => Promise<T_8>;
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
        listTags: <T_9 extends unknown>(functionId: string, search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T_9>;
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
         * @returns {Promise}
         */
        createTag: <T_10 extends unknown>(functionId: string, command: string, code: File) => Promise<T_10>;
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
        getTag: <T_11 extends unknown>(functionId: string, tagId: string) => Promise<T_11>;
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
        deleteTag: <T_12 extends unknown>(functionId: string, tagId: string) => Promise<T_12>;
        /**
         * Get Function Usage
         *
         *
         * @param {string} functionId
         * @param {string} range
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getUsage: <T_13 extends unknown>(functionId: string, range?: string | undefined) => Promise<T_13>;
    };
    health: {
        /**
         * Get HTTP
         *
         * Check the Appwrite HTTP server is up and responsive.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        get: <T extends unknown>() => Promise<T>;
        /**
         * Get Anti virus
         *
         * Check the Appwrite Anti Virus server is up and connection is successful.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getAntiVirus: <T_1 extends unknown>() => Promise<T_1>;
        /**
         * Get Cache
         *
         * Check the Appwrite in-memory cache server is up and connection is
         * successful.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getCache: <T_2 extends unknown>() => Promise<T_2>;
        /**
         * Get DB
         *
         * Check the Appwrite database server is up and connection is successful.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getDB: <T_3 extends unknown>() => Promise<T_3>;
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
        getQueueCertificates: <T_4 extends unknown>() => Promise<T_4>;
        /**
         * Get Functions Queue
         *
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getQueueFunctions: <T_5 extends unknown>() => Promise<T_5>;
        /**
         * Get Logs Queue
         *
         * Get the number of logs that are waiting to be processed in the Appwrite
         * internal queue server.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getQueueLogs: <T_6 extends unknown>() => Promise<T_6>;
        /**
         * Get Tasks Queue
         *
         * Get the number of tasks that are waiting to be processed in the Appwrite
         * internal queue server.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getQueueTasks: <T_7 extends unknown>() => Promise<T_7>;
        /**
         * Get Usage Queue
         *
         * Get the number of usage stats that are waiting to be processed in the
         * Appwrite internal queue server.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getQueueUsage: <T_8 extends unknown>() => Promise<T_8>;
        /**
         * Get Webhooks Queue
         *
         * Get the number of webhooks that are waiting to be processed in the Appwrite
         * internal queue server.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getQueueWebhooks: <T_9 extends unknown>() => Promise<T_9>;
        /**
         * Get Local Storage
         *
         * Check the Appwrite local storage device is up and connection is successful.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getStorageLocal: <T_10 extends unknown>() => Promise<T_10>;
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
        getTime: <T_11 extends unknown>() => Promise<T_11>;
    };
    locale: {
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
        get: <T extends unknown>() => Promise<T>;
        /**
         * List Continents
         *
         * List of all continents. You can use the locale header to get the data in a
         * supported language.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getContinents: <T_1 extends unknown>() => Promise<T_1>;
        /**
         * List Countries
         *
         * List of all countries. You can use the locale header to get the data in a
         * supported language.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getCountries: <T_2 extends unknown>() => Promise<T_2>;
        /**
         * List EU Countries
         *
         * List of all countries that are currently members of the EU. You can use the
         * locale header to get the data in a supported language.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getCountriesEU: <T_3 extends unknown>() => Promise<T_3>;
        /**
         * List Countries Phone Codes
         *
         * List of all countries phone codes. You can use the locale header to get the
         * data in a supported language.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getCountriesPhones: <T_4 extends unknown>() => Promise<T_4>;
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
        getCurrencies: <T_5 extends unknown>() => Promise<T_5>;
        /**
         * List Languages
         *
         * List of all languages classified by ISO 639-1 including 2-letter code, name
         * in English, and name in the respective language.
         *
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getLanguages: <T_6 extends unknown>() => Promise<T_6>;
    };
    projects: {
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
        list: <T extends unknown>(search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T>;
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
         * @returns {Promise}
         */
        create: <T_1 extends unknown>(name: string, teamId: string, description?: string | undefined, logo?: string | undefined, url?: string | undefined, legalName?: string | undefined, legalCountry?: string | undefined, legalState?: string | undefined, legalCity?: string | undefined, legalAddress?: string | undefined, legalTaxId?: string | undefined) => Promise<T_1>;
        /**
         * Get Project
         *
         *
         * @param {string} projectId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        get: <T_2 extends unknown>(projectId: string) => Promise<T_2>;
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
        update: <T_3 extends unknown>(projectId: string, name: string, description?: string | undefined, logo?: string | undefined, url?: string | undefined, legalName?: string | undefined, legalCountry?: string | undefined, legalState?: string | undefined, legalCity?: string | undefined, legalAddress?: string | undefined, legalTaxId?: string | undefined) => Promise<T_3>;
        /**
         * Delete Project
         *
         *
         * @param {string} projectId
         * @param {string} password
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        delete: <T_4 extends unknown>(projectId: string, password: string) => Promise<T_4>;
        /**
         * Update Project users limit
         *
         *
         * @param {string} projectId
         * @param {number} limit
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        updateAuthLimit: <T_5 extends unknown>(projectId: string, limit: number) => Promise<T_5>;
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
        updateAuthStatus: <T_6 extends unknown>(projectId: string, method: string, status: boolean) => Promise<T_6>;
        /**
         * List Domains
         *
         *
         * @param {string} projectId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        listDomains: <T_7 extends unknown>(projectId: string) => Promise<T_7>;
        /**
         * Create Domain
         *
         *
         * @param {string} projectId
         * @param {string} domain
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        createDomain: <T_8 extends unknown>(projectId: string, domain: string) => Promise<T_8>;
        /**
         * Get Domain
         *
         *
         * @param {string} projectId
         * @param {string} domainId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getDomain: <T_9 extends unknown>(projectId: string, domainId: string) => Promise<T_9>;
        /**
         * Delete Domain
         *
         *
         * @param {string} projectId
         * @param {string} domainId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deleteDomain: <T_10 extends unknown>(projectId: string, domainId: string) => Promise<T_10>;
        /**
         * Update Domain Verification Status
         *
         *
         * @param {string} projectId
         * @param {string} domainId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        updateDomainVerification: <T_11 extends unknown>(projectId: string, domainId: string) => Promise<T_11>;
        /**
         * List Keys
         *
         *
         * @param {string} projectId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        listKeys: <T_12 extends unknown>(projectId: string) => Promise<T_12>;
        /**
         * Create Key
         *
         *
         * @param {string} projectId
         * @param {string} name
         * @param {string[]} scopes
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        createKey: <T_13 extends unknown>(projectId: string, name: string, scopes: string[]) => Promise<T_13>;
        /**
         * Get Key
         *
         *
         * @param {string} projectId
         * @param {string} keyId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getKey: <T_14 extends unknown>(projectId: string, keyId: string) => Promise<T_14>;
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
        updateKey: <T_15 extends unknown>(projectId: string, keyId: string, name: string, scopes: string[]) => Promise<T_15>;
        /**
         * Delete Key
         *
         *
         * @param {string} projectId
         * @param {string} keyId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deleteKey: <T_16 extends unknown>(projectId: string, keyId: string) => Promise<T_16>;
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
        updateOAuth2: <T_17 extends unknown>(projectId: string, provider: string, appId?: string | undefined, secret?: string | undefined) => Promise<T_17>;
        /**
         * List Platforms
         *
         *
         * @param {string} projectId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        listPlatforms: <T_18 extends unknown>(projectId: string) => Promise<T_18>;
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
         * @returns {Promise}
         */
        createPlatform: <T_19 extends unknown>(projectId: string, type: string, name: string, key?: string | undefined, store?: string | undefined, hostname?: string | undefined) => Promise<T_19>;
        /**
         * Get Platform
         *
         *
         * @param {string} projectId
         * @param {string} platformId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getPlatform: <T_20 extends unknown>(projectId: string, platformId: string) => Promise<T_20>;
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
        updatePlatform: <T_21 extends unknown>(projectId: string, platformId: string, name: string, key?: string | undefined, store?: string | undefined, hostname?: string | undefined) => Promise<T_21>;
        /**
         * Delete Platform
         *
         *
         * @param {string} projectId
         * @param {string} platformId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deletePlatform: <T_22 extends unknown>(projectId: string, platformId: string) => Promise<T_22>;
        /**
         * List Tasks
         *
         *
         * @param {string} projectId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        listTasks: <T_23 extends unknown>(projectId: string) => Promise<T_23>;
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
         * @returns {Promise}
         */
        createTask: <T_24 extends unknown>(projectId: string, name: string, status: string, schedule: string, security: boolean, httpMethod: string, httpUrl: string, httpHeaders?: string[] | undefined, httpUser?: string | undefined, httpPass?: string | undefined) => Promise<T_24>;
        /**
         * Get Task
         *
         *
         * @param {string} projectId
         * @param {string} taskId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getTask: <T_25 extends unknown>(projectId: string, taskId: string) => Promise<T_25>;
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
        updateTask: <T_26 extends unknown>(projectId: string, taskId: string, name: string, status: string, schedule: string, security: boolean, httpMethod: string, httpUrl: string, httpHeaders?: string[] | undefined, httpUser?: string | undefined, httpPass?: string | undefined) => Promise<T_26>;
        /**
         * Delete Task
         *
         *
         * @param {string} projectId
         * @param {string} taskId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deleteTask: <T_27 extends unknown>(projectId: string, taskId: string) => Promise<T_27>;
        /**
         * Get Project
         *
         *
         * @param {string} projectId
         * @param {string} range
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getUsage: <T_28 extends unknown>(projectId: string, range?: string | undefined) => Promise<T_28>;
        /**
         * List Webhooks
         *
         *
         * @param {string} projectId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        listWebhooks: <T_29 extends unknown>(projectId: string) => Promise<T_29>;
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
         * @returns {Promise}
         */
        createWebhook: <T_30 extends unknown>(projectId: string, name: string, events: string[], url: string, security: boolean, httpUser?: string | undefined, httpPass?: string | undefined) => Promise<T_30>;
        /**
         * Get Webhook
         *
         *
         * @param {string} projectId
         * @param {string} webhookId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getWebhook: <T_31 extends unknown>(projectId: string, webhookId: string) => Promise<T_31>;
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
        updateWebhook: <T_32 extends unknown>(projectId: string, webhookId: string, name: string, events: string[], url: string, security: boolean, httpUser?: string | undefined, httpPass?: string | undefined) => Promise<T_32>;
        /**
         * Delete Webhook
         *
         *
         * @param {string} projectId
         * @param {string} webhookId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deleteWebhook: <T_33 extends unknown>(projectId: string, webhookId: string) => Promise<T_33>;
    };
    storage: {
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
        listFiles: <T extends unknown>(search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T>;
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
        createFile: <T_1 extends unknown>(file: File, read?: string[] | undefined, write?: string[] | undefined) => Promise<T_1>;
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
        getFile: <T_2 extends unknown>(fileId: string) => Promise<T_2>;
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
        updateFile: <T_3 extends unknown>(fileId: string, read: string[], write: string[]) => Promise<T_3>;
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
        deleteFile: <T_4 extends unknown>(fileId: string) => Promise<T_4>;
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
        getFileDownload: (fileId: string) => URL;
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
        getFilePreview: (fileId: string, width?: number | undefined, height?: number | undefined, gravity?: string | undefined, quality?: number | undefined, borderWidth?: number | undefined, borderColor?: string | undefined, borderRadius?: number | undefined, opacity?: number | undefined, rotation?: number | undefined, background?: string | undefined, output?: string | undefined) => URL;
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
        getFileView: (fileId: string) => URL;
    };
    teams: {
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
        list: <T extends unknown>(search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T>;
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
         * @returns {Promise}
         */
        create: <T_1 extends unknown>(name: string, roles?: string[] | undefined) => Promise<T_1>;
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
        get: <T_2 extends unknown>(teamId: string) => Promise<T_2>;
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
        update: <T_3 extends unknown>(teamId: string, name: string) => Promise<T_3>;
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
        delete: <T_4 extends unknown>(teamId: string) => Promise<T_4>;
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
        getMemberships: <T_5 extends unknown>(teamId: string, search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T_5>;
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
         * @returns {Promise}
         */
        createMembership: <T_6 extends unknown>(teamId: string, email: string, roles: string[], url: string, name?: string | undefined) => Promise<T_6>;
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
        updateMembershipRoles: <T_7 extends unknown>(teamId: string, membershipId: string, roles: string[]) => Promise<T_7>;
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
        deleteMembership: <T_8 extends unknown>(teamId: string, membershipId: string) => Promise<T_8>;
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
        updateMembershipStatus: <T_9 extends unknown>(teamId: string, membershipId: string, userId: string, secret: string) => Promise<T_9>;
    };
    users: {
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
        list: <T extends unknown>(search?: string | undefined, limit?: number | undefined, offset?: number | undefined, orderType?: string | undefined) => Promise<T>;
        /**
         * Create User
         *
         * Create a new user.
         *
         * @param {string} email
         * @param {string} password
         * @param {string} name
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        create: <T_1 extends unknown>(email: string, password: string, name?: string | undefined) => Promise<T_1>;
        /**
         * Get User
         *
         * Get a user by its unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        get: <T_2 extends unknown>(userId: string) => Promise<T_2>;
        /**
         * Delete User
         *
         * Delete a user by its unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        delete: <T_3 extends unknown>(userId: string) => Promise<T_3>;
        /**
         * Get User Logs
         *
         * Get a user activity logs list by its unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getLogs: <T_4 extends unknown>(userId: string) => Promise<T_4>;
        /**
         * Get User Preferences
         *
         * Get the user preferences by its unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getPrefs: <T_5 extends unknown>(userId: string) => Promise<T_5>;
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
        updatePrefs: <T_6 extends unknown>(userId: string, prefs: object) => Promise<T_6>;
        /**
         * Get User Sessions
         *
         * Get the user sessions list by its unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        getSessions: <T_7 extends unknown>(userId: string) => Promise<T_7>;
        /**
         * Delete User Sessions
         *
         * Delete all user's sessions by using the user's unique ID.
         *
         * @param {string} userId
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        deleteSessions: <T_8 extends unknown>(userId: string) => Promise<T_8>;
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
        deleteSession: <T_9 extends unknown>(userId: string, sessionId: string) => Promise<T_9>;
        /**
         * Update User Status
         *
         * Update the user status by its unique ID.
         *
         * @param {string} userId
         * @param {number} status
         * @throws {AppwriteException}
         * @returns {Promise}
         */
        updateStatus: <T_10 extends unknown>(userId: string, status: number) => Promise<T_10>;
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
        updateVerification: <T_11 extends unknown>(userId: string, emailVerification: boolean) => Promise<T_11>;
    };
}
export { Appwrite };
export type { AppwriteException };
