import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Avatars

val client = Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

val avatars = Avatars(client)

val result = avatars.getImage(
    url = "https://example.com", 
    width = 0, // (optional)
    height = 0, // (optional)
)