import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Avatars

val client = Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

val avatars = Avatars(client)

val result = avatars.getScreenshot(
    url = "https://example.com", 
    headers = mapOf( "a" to "b" ), // (optional)
    viewportWidth = 1, // (optional)
    viewportHeight = 1, // (optional)
    scale = 0.1, // (optional)
    theme = theme.LIGHT, // (optional)
    userAgent = "<USER_AGENT>", // (optional)
    fullpage = false, // (optional)
    locale = "<LOCALE>", // (optional)
    timezone = timezone.AFRICA_ABIDJAN, // (optional)
    latitude = -90, // (optional)
    longitude = -180, // (optional)
    accuracy = 0, // (optional)
    touch = false, // (optional)
    permissions = listOf(), // (optional)
    sleep = 0, // (optional)
    width = 0, // (optional)
    height = 0, // (optional)
    quality = -1, // (optional)
    output = output.JPG, // (optional)
)