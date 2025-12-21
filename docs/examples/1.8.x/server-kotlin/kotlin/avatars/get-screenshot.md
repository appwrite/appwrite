import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Avatars
import io.appwrite.enums.Theme
import io.appwrite.enums.Timezone
import io.appwrite.enums.Output

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession("") // The user session to authenticate with

val avatars = Avatars(client)

val result = avatars.getScreenshot(
    url = "https://example.com",
    headers = mapOf(
        "Authorization" to "Bearer token123",
        "X-Custom-Header" to "value"
    ), // optional
    viewportWidth = 1920, // optional
    viewportHeight = 1080, // optional
    scale = 2, // optional
    theme = "dark", // optional
    userAgent = "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15", // optional
    fullpage = true, // optional
    locale = "en-US", // optional
    timezone = "america/new_york", // optional
    latitude = 37.7749, // optional
    longitude = -122.4194, // optional
    accuracy = 100, // optional
    touch = true, // optional
    permissions = listOf("geolocation", "notifications"), // optional
    sleep = 3, // optional
    width = 800, // optional
    height = 600, // optional
    quality = 85, // optional
    output = "jpeg" // optional
)
