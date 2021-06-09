package io.appwrite.services

import android.net.Uri
import io.appwrite.Client
import io.appwrite.exceptions.AppwriteException
import okhttp3.Cookie
import okhttp3.Response
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import java.io.File

class Avatars(private val client: Client) : BaseService(client) {

    /**
     * Get Browser Icon
     *
     * You can use this endpoint to show different browser icons to your users.
     * The code argument receives the browser code as it appears in your user
     * /account/sessions endpoint. Use width, height and quality arguments to
     * change the output settings.
     *
     * @param code
     * @param width
     * @param height
     * @param quality
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getBrowser(
		code: String,
		width: Int? = null,
		height: Int? = null,
		quality: Int? = null
	): Response {
        val path = "/avatars/browsers/{code}".replace("{code}", code)
        val params = mapOf<String, Any?>(
            "width" to width,
            "height" to height,
            "quality" to quality,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get Credit Card Icon
     *
     * The credit card endpoint will return you the icon of the credit card
     * provider you need. Use width, height and quality arguments to change the
     * output settings.
     *
     * @param code
     * @param width
     * @param height
     * @param quality
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getCreditCard(
		code: String,
		width: Int? = null,
		height: Int? = null,
		quality: Int? = null
	): Response {
        val path = "/avatars/credit-cards/{code}".replace("{code}", code)
        val params = mapOf<String, Any?>(
            "width" to width,
            "height" to height,
            "quality" to quality,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get Favicon
     *
     * Use this endpoint to fetch the favorite icon (AKA favicon) of any remote
     * website URL.
     * 
     *
     * @param url
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getFavicon(
		url: String
	): Response {
        val path = "/avatars/favicon"
        val params = mapOf<String, Any?>(
            "url" to url,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get Country Flag
     *
     * You can use this endpoint to show different country flags icons to your
     * users. The code argument receives the 2 letter country code. Use width,
     * height and quality arguments to change the output settings.
     *
     * @param code
     * @param width
     * @param height
     * @param quality
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getFlag(
		code: String,
		width: Int? = null,
		height: Int? = null,
		quality: Int? = null
	): Response {
        val path = "/avatars/flags/{code}".replace("{code}", code)
        val params = mapOf<String, Any?>(
            "width" to width,
            "height" to height,
            "quality" to quality,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get Image from URL
     *
     * Use this endpoint to fetch a remote image URL and crop it to any image size
     * you want. This endpoint is very useful if you need to crop and display
     * remote images in your app or in case you want to make sure a 3rd party
     * image is properly served using a TLS protocol.
     *
     * @param url
     * @param width
     * @param height
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getImage(
		url: String,
		width: Int? = null,
		height: Int? = null
	): Response {
        val path = "/avatars/image"
        val params = mapOf<String, Any?>(
            "url" to url,
            "width" to width,
            "height" to height,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
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
     * @param name
     * @param width
     * @param height
     * @param color
     * @param background
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getInitials(
		name: String? = null,
		width: Int? = null,
		height: Int? = null,
		color: String? = null,
		background: String? = null
	): Response {
        val path = "/avatars/initials"
        val params = mapOf<String, Any?>(
            "name" to name,
            "width" to width,
            "height" to height,
            "color" to color,
            "background" to background,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get QR Code
     *
     * Converts a given plain text to a QR code image. You can use the query
     * parameters to change the size and style of the resulting image.
     *
     * @param text
     * @param size
     * @param margin
     * @param download
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getQR(
		text: String,
		size: Int? = null,
		margin: Int? = null,
		download: Boolean? = null
	): Response {
        val path = "/avatars/qr"
        val params = mapOf<String, Any?>(
            "text" to text,
            "size" to size,
            "margin" to margin,
            "download" to download,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
}