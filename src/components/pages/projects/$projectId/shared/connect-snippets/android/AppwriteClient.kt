import android.content.Context
import io.appwrite.Client

object AppwriteClient {
  @Volatile private var instance: Client? = null

  fun get(context: Context): Client =
    instance ?: synchronized(this) {
      instance ?: Client(context.applicationContext)
        .setEndpoint(BuildConfig.APPWRITE_ENDPOINT)
        .setProject(BuildConfig.APPWRITE_PROJECT_ID)
        .also { instance = it }
    }
}
