package io.appwrite.services

import android.net.Uri
import io.appwrite.Client
import io.appwrite.WebAuthComponent
import androidx.activity.ComponentActivity
import io.appwrite.exceptions.AppwriteException
import okhttp3.Cookie
import okhttp3.Response
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import java.io.File

class Account(private val client: Client) : BaseService(client) {

    /**
     * Get Account
     *
     * Get currently logged in user data as JSON object.
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun get(): Response {
        val path = "/account"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("GET", path, headers, params)
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
     * @param email
     * @param password
     * @param name
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun create(
		email: String,
		password: String,
		name: String? = null
	): Response {
        val path = "/account"
        val params = mapOf<String, Any?>(
            "email" to email,
            "password" to password,
            "name" to name
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("POST", path, headers, params)
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
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun delete(): Response {
        val path = "/account"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("DELETE", path, headers, params)
    }
    
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
     * @param email
     * @param password
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updateEmail(
		email: String,
		password: String
	): Response {
        val path = "/account/email"
        val params = mapOf<String, Any?>(
            "email" to email,
            "password" to password
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PATCH", path, headers, params)
    }
    
    /**
     * Create Account JWT
     *
     * Use this endpoint to create a JSON Web Token. You can use the resulting JWT
     * to authenticate on behalf of the current user when working with the
     * Appwrite server-side API and SDKs. The JWT secret is valid for 15 minutes
     * from its creation and will be invalid if the user will logout.
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createJWT(): Response {
        val path = "/account/jwt"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("POST", path, headers, params)
    }
    
    /**
     * Get Account Logs
     *
     * Get currently logged in user list of latest security activity logs. Each
     * log returns user IP address, location and date and time of log.
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getLogs(): Response {
        val path = "/account/logs"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("GET", path, headers, params)
    }
    
    /**
     * Update Account Name
     *
     * Update currently logged in user account name.
     *
     * @param name
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updateName(
		name: String
	): Response {
        val path = "/account/name"
        val params = mapOf<String, Any?>(
            "name" to name
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PATCH", path, headers, params)
    }
    
    /**
     * Update Account Password
     *
     * Update currently logged in user password. For validation, user is required
     * to pass in the new password, and the old password. For users created with
     * OAuth and Team Invites, oldPassword is optional.
     *
     * @param password
     * @param oldPassword
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updatePassword(
		password: String,
		oldPassword: String? = null
	): Response {
        val path = "/account/password"
        val params = mapOf<String, Any?>(
            "password" to password,
            "oldPassword" to oldPassword
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PATCH", path, headers, params)
    }
    
    /**
     * Get Account Preferences
     *
     * Get currently logged in user preferences as a key-value object.
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getPrefs(): Response {
        val path = "/account/prefs"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("GET", path, headers, params)
    }
    
    /**
     * Update Account Preferences
     *
     * Update currently logged in user account preferences. You can pass only the
     * specific settings you wish to update.
     *
     * @param prefs
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updatePrefs(
		prefs: Any
	): Response {
        val path = "/account/prefs"
        val params = mapOf<String, Any?>(
            "prefs" to prefs
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PATCH", path, headers, params)
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
     * @param email
     * @param url
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createRecovery(
		email: String,
		url: String
	): Response {
        val path = "/account/recovery"
        val params = mapOf<String, Any?>(
            "email" to email,
            "url" to url
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("POST", path, headers, params)
    }
    
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
     * @param userId
     * @param secret
     * @param password
     * @param passwordAgain
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updateRecovery(
		userId: String,
		secret: String,
		password: String,
		passwordAgain: String
	): Response {
        val path = "/account/recovery"
        val params = mapOf<String, Any?>(
            "userId" to userId,
            "secret" to secret,
            "password" to password,
            "passwordAgain" to passwordAgain
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PUT", path, headers, params)
    }
    
    /**
     * Get Account Sessions
     *
     * Get currently logged in user list of active sessions across different
     * devices.
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getSessions(): Response {
        val path = "/account/sessions"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("GET", path, headers, params)
    }
    
    /**
     * Create Account Session
     *
     * Allow the user to login into their account by providing a valid email and
     * password combination. This route will create a new session for the user.
     *
     * @param email
     * @param password
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createSession(
		email: String,
		password: String
	): Response {
        val path = "/account/sessions"
        val params = mapOf<String, Any?>(
            "email" to email,
            "password" to password
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("POST", path, headers, params)
    }
    
    /**
     * Delete All Account Sessions
     *
     * Delete all sessions from the user account and remove any sessions cookies
     * from the end client.
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun deleteSessions(): Response {
        val path = "/account/sessions"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("DELETE", path, headers, params)
    }
    
    /**
     * Create Anonymous Session
     *
     * Use this endpoint to allow a new user to register an anonymous account in
     * your project. This route will also create a new session for the user. To
     * allow the new user to convert an anonymous account to a normal account
     * account, you need to update its [email and
     * password](/docs/client/account#accountUpdateEmail).
     *
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createAnonymousSession(): Response {
        val path = "/account/sessions/anonymous"
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("POST", path, headers, params)
    }
    
    /**
     * Create Account Session with OAuth2
     *
     * Allow the user to login to their account using the OAuth2 provider of their
     * choice. Each OAuth2 provider should be enabled from the Appwrite console
     * first. Use the success and failure arguments to provide a redirect URL's
     * back to your app when login is completed.
     *
     * @param provider
     * @param success
     * @param failure
     * @param scopes
     *      
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createOAuth2Session(
		activity: ComponentActivity, 
		provider: String,
		success: String? = null,
		failure: String? = null,
		scopes: List<Any>? = null
	) {
        val path = "/account/sessions/oauth2/{provider}".replace("{provider}", provider)
        val params = mapOf<String, Any?>(
            "success" to success,
            "failure" to failure,
            "scopes" to scopes,
            "project" to client.config["project"]
        )

        val query = mutableListOf<String>()
        params.forEach {
            when (it.value) {
                null -> {
                    return@forEach
                }
                is List<*> -> {
                    query.add("${it.key}[]=${it.value.toString()}")
                }
                else -> {
                   query.add("${it.key}=${it.value.toString()}")
                }
            }
        }

        val url = Uri.parse("${client.endPoint}${path}?${query.joinToString("&")}")
        val callbackUrlScheme = "appwrite-callback-${client.config["project"]}"

        WebAuthComponent.authenticate(activity, url, callbackUrlScheme) {
            if (it.isFailure) {
                throw it.exceptionOrNull()!!
            }

            val resultUrl = it.getOrNull()!!
            val uri = Uri.parse(resultUrl)
            val key = uri.getQueryParameter("key")
            val secret = uri.getQueryParameter("secret")
            if (key == null || secret == null) {
                throw AppwriteException("Authentication cookie missing!")
            }
            val cookie = Cookie.Builder()
                .name(key)
                .value(secret)
                .domain(Uri.parse(client.endPoint).host!!)
                .httpOnly()
                .build()
                
            client.cookieJar.saveFromResponse(
                client.endPoint.toHttpUrl(),
                listOf(cookie)
            )
        }

    }
    
    /**
     * Delete Account Session
     *
     * Use this endpoint to log out the currently logged in user from all their
     * account sessions across all of their different devices. When using the
     * option id argument, only the session unique ID provider will be deleted.
     *
     * @param sessionId
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun deleteSession(
		sessionId: String
	): Response {
        val path = "/account/sessions/{sessionId}".replace("{sessionId}", sessionId)
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("DELETE", path, headers, params)
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
     * process](/docs/client/account#accountUpdateVerification). The verification
     * link sent to the user's email address is valid for 7 days.
     * 
     * Please note that in order to avoid a [Redirect
     * Attack](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md),
     * the only valid redirect URLs are the ones from domains you have set when
     * adding your platforms in the console interface.
     * 
     *
     * @param url
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createVerification(
		url: String
	): Response {
        val path = "/account/verification"
        val params = mapOf<String, Any?>(
            "url" to url
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("POST", path, headers, params)
    }
    
    /**
     * Complete Email Verification
     *
     * Use this endpoint to complete the user email verification process. Use both
     * the **userId** and **secret** parameters that were attached to your app URL
     * to verify the user email ownership. If confirmed this route will return a
     * 200 status code.
     *
     * @param userId
     * @param secret
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updateVerification(
		userId: String,
		secret: String
	): Response {
        val path = "/account/verification"
        val params = mapOf<String, Any?>(
            "userId" to userId,
            "secret" to secret
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PUT", path, headers, params)
    }
    
}