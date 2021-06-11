package io.appwrite.views

import android.app.Activity
import android.os.Bundle
import io.appwrite.WebAuthComponent

class CallbackActivity: Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val url = intent?.data
        val scheme = url?.scheme
        if (scheme != null) {
            // Found a scheme, try to callback to web auth component.
            // Will only suceed if the scheme matches one launched by this sdk.
            WebAuthComponent.onCallback(scheme, url)
        }
        finish()
    }
}