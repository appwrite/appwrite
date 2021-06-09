package io.appwrite.extensions

import com.google.gson.Gson

object JsonExtensions {

    fun Any.toJson(): String =
        Gson().toJson(this)

    fun <T> String.fromJson(clazz: Class<T>): T =
        Gson().fromJson(this, clazz)
}