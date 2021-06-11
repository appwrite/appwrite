package io.appwrite

import android.content.Intent
import android.net.Uri
import androidx.activity.ComponentActivity
import androidx.browser.customtabs.CustomTabsIntent
import androidx.lifecycle.DefaultLifecycleObserver
import androidx.lifecycle.LifecycleOwner
import io.appwrite.services.KeepAliveService
import kotlinx.coroutines.delay
import kotlin.collections.component1
import kotlin.collections.component2
import kotlin.collections.forEach
import kotlin.collections.mutableMapOf
import kotlin.collections.set

/**
 * Used to authenticate with external OAuth2 providers. Launches browser windows and handles
 * suspension until the user completes the process or otherwise returns to the app.
 */
internal class WebAuthComponent {

    companion object : DefaultLifecycleObserver {
        private var suspended = false
        private val callbacks = mutableMapOf<String, (((Result<String>) -> Unit)?)>()

        override fun onResume(owner: LifecycleOwner) {
            suspended = false
        }

        /**
         * Authenticate Session with OAuth2
         *
         * Launches a chrome custom tab from the given activity and directs to the given url,
         * suspending until the user returns to the app, at which point the given [onComplete] callback
         * will run, passing the callback url from the intent used to launch the [CallbackActivity],
         * or an [IllegalStateException] in the case the user closed the window or returned to the
         * app without passing through the [CallbackActivity].
         *
         *
         * @param activity              The activity to launch the browser from and observe the lifecycle of
         * @param url                   The url to launch
         * @param callbackUrlScheme     The callback url scheme used to key the given callback
         * @param onComplete            The callback to run when a result (success or failure) is received
         */
        suspend fun authenticate(
            activity: ComponentActivity,
            url: Uri,
            callbackUrlScheme: String,
            onComplete: ((Result<String>) -> Unit)?
        ) {
            val intent = CustomTabsIntent.Builder().build()
            val keepAliveIntent = Intent(activity, KeepAliveService::class.java)

            callbacks[callbackUrlScheme] = onComplete

            intent.intent.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
            intent.intent.putExtra("android.support.customtabs.extra.KEEP_ALIVE", keepAliveIntent)
            intent.launchUrl(activity, url)

            activity.runOnUiThread {
                activity.lifecycle.addObserver(this)
            }

            // Need to dirty poll block so execution doesn't continue at the callsite of this function
            suspended = true
            while (suspended) {
                delay(200)
            }
            cleanUp()
        }

        /**
         * Trigger a web auth callback
         *
         * Attempts to find a callback for the given [scheme] and if found, invokes it, passing the
         * given [url]. Calling this method stops auth suspension, so any calls to [authenticate]
         * will continue execution from their suspension points immediately after this method
         * is called.
         *
         * @param scheme    The scheme to match to a callback's key
         * @param url       The url received through intent data from the [CallbackActivity]
         */
        fun onCallback(scheme: String, url: Uri) {
            callbacks.remove(scheme)?.invoke(
                Result.success(url.toString())
            )
            suspended = false
        }

        private fun cleanUp() {
            callbacks.forEach { (_, danglingResultCallback) ->
                danglingResultCallback?.invoke(
                    Result.failure(IllegalStateException("User cancelled login"))
                )
            }
            callbacks.clear()
        }
    }
}