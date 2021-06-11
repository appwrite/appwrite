package io.appwrite.services

import android.net.Uri
import io.appwrite.Client
import io.appwrite.exceptions.AppwriteException
import okhttp3.Cookie
import okhttp3.Response
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import java.io.File

class Storage(private val client: Client) : BaseService(client) {

    /**
     * List Files
     *
     * Get a list of all the user files. You can use the query params to filter
     * your results. On admin mode, this endpoint will return a list of all of the
     * project's files. [Learn more about different API modes](/docs/admin).
     *
     * @param search
     * @param limit
     * @param offset
     * @param orderType
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun listFiles(
		search: String? = null,
		limit: Int? = null,
		offset: Int? = null,
		orderType: String? = null
	): Response {
        val path = "/storage/files"
        val params = mapOf<String, Any?>(
            "search" to search,
            "limit" to limit,
            "offset" to offset,
            "orderType" to orderType
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("GET", path, headers, params)
    }
    
    /**
     * Create File
     *
     * Create a new file. The user who creates the file will automatically be
     * assigned to read and write access unless he has passed custom values for
     * read and write arguments.
     *
     * @param file
     * @param read
     * @param write
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun createFile(
		file: File,
		read: List<Any>? = null,
		write: List<Any>? = null
	): Response {
        val path = "/storage/files"
        val params = mapOf<String, Any?>(
            "file" to file,
            "read" to read,
            "write" to write
        )

        val headers = mapOf(
            "content-type" to "multipart/form-data"
        )

        return client.call("POST", path, headers, params)
    }
    
    /**
     * Get File
     *
     * Get a file by its unique ID. This endpoint response returns a JSON object
     * with the file metadata.
     *
     * @param fileId
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getFile(
		fileId: String
	): Response {
        val path = "/storage/files/{fileId}".replace("{fileId}", fileId)
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("GET", path, headers, params)
    }
    
    /**
     * Update File
     *
     * Update a file by its unique ID. Only users with write permissions have
     * access to update this resource.
     *
     * @param fileId
     * @param read
     * @param write
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun updateFile(
		fileId: String,
		read: List<Any>,
		write: List<Any>
	): Response {
        val path = "/storage/files/{fileId}".replace("{fileId}", fileId)
        val params = mapOf<String, Any?>(
            "read" to read,
            "write" to write
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("PUT", path, headers, params)
    }
    
    /**
     * Delete File
     *
     * Delete a file by its unique ID. Only users with write permissions have
     * access to delete this resource.
     *
     * @param fileId
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun deleteFile(
		fileId: String
	): Response {
        val path = "/storage/files/{fileId}".replace("{fileId}", fileId)
        val params = mapOf<String, Any?>(
        )

        val headers = mapOf(
            "content-type" to "application/json"
        )

        return client.call("DELETE", path, headers, params)
    }
    
    /**
     * Get File for Download
     *
     * Get a file content by its unique ID. The endpoint response return with a
     * 'Content-Disposition: attachment' header that tells the browser to start
     * downloading the file to user downloads directory.
     *
     * @param fileId
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getFileDownload(
		fileId: String
	): Response {
        val path = "/storage/files/{fileId}/download".replace("{fileId}", fileId)
        val params = mapOf<String, Any?>(
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get File Preview
     *
     * Get a file preview image. Currently, this method supports preview for image
     * files (jpg, png, and gif), other supported formats, like pdf, docs, slides,
     * and spreadsheets, will return the file icon image. You can also pass query
     * string arguments for cutting and resizing your preview image.
     *
     * @param fileId
     * @param width
     * @param height
     * @param quality
     * @param borderWidth
     * @param borderColor
     * @param borderRadius
     * @param opacity
     * @param rotation
     * @param background
     * @param output
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getFilePreview(
		fileId: String,
		width: Int? = null,
		height: Int? = null,
		quality: Int? = null,
		borderWidth: Int? = null,
		borderColor: String? = null,
		borderRadius: Int? = null,
		opacity: Double? = null,
		rotation: Int? = null,
		background: String? = null,
		output: String? = null
	): Response {
        val path = "/storage/files/{fileId}/preview".replace("{fileId}", fileId)
        val params = mapOf<String, Any?>(
            "width" to width,
            "height" to height,
            "quality" to quality,
            "borderWidth" to borderWidth,
            "borderColor" to borderColor,
            "borderRadius" to borderRadius,
            "opacity" to opacity,
            "rotation" to rotation,
            "background" to background,
            "output" to output,
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
    /**
     * Get File for View
     *
     * Get a file content by its unique ID. This endpoint is similar to the
     * download method but returns with no  'Content-Disposition: attachment'
     * header.
     *
     * @param fileId
     * @return [Response]     
     */
    @JvmOverloads
    @Throws(AppwriteException::class)
    suspend fun getFileView(
		fileId: String
	): Response {
        val path = "/storage/files/{fileId}/view".replace("{fileId}", fileId)
        val params = mapOf<String, Any?>(
            "project" to client.config["project"]
        )

        return client.call("GET", path, params = params)
    }
    
}