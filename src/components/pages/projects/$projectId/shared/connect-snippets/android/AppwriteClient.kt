import android.content.Context
import io.appwrite.Client

// System.getenv() returns null on Android - there is no process environment
// to read. The values come from gradle.properties via BuildConfig instead;
// see build.gradle.kts.
object AppwriteClient {
  @Volatile private var instance: Client? = null

  // The Android SDK's Client needs a Context to persist the session.
  fun get(context: Context): Client =
    instance ?: synchronized(this) {
      instance ?: Client(context.applicationContext)
        .setEndpoint(BuildConfig.APPWRITE_ENDPOINT)
        .setProject(BuildConfig.APPWRITE_PROJECT_ID)
        .also { instance = it }
    }
}
