package io.appwrite.exceptions

import java.lang.Exception

class AppwriteException(
    message: String? = null,
    val code: Int? = null,
    val response: String? = null
) : Exception(message)