package io.appwrite.android.utils

import android.content.Context
import io.appwrite.Client

object Client {
    lateinit var client : Client

    fun create(context: Context) {
        client = Client(context)
                .setEndpoint("https://demo.appwrite.io/v1")
                .setProject("6070749e6acd4")

        /* Useful when testing locally */     
//        client = Client(context)
//            .setEndpoint("https://192.168.1.35/v1")
//            .setProject("60bdbc911784e")
//            .setSelfSigned(true)
    }
}