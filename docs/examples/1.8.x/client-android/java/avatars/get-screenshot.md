import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Avatars;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Avatars avatars = new Avatars(client);

avatars.getScreenshot(
    "https://example.com", // url 
    mapOf( "a" to "b" ), // headers (optional)
    1, // viewportWidth (optional)
    1, // viewportHeight (optional)
    0.1, // scale (optional)
    theme.LIGHT, // theme (optional)
    "<USER_AGENT>", // userAgent (optional)
    false, // fullpage (optional)
    "<LOCALE>", // locale (optional)
    timezone.AFRICA_ABIDJAN, // timezone (optional)
    -90, // latitude (optional)
    -180, // longitude (optional)
    0, // accuracy (optional)
    false, // touch (optional)
    listOf(), // permissions (optional)
    0, // sleep (optional)
    0, // width (optional)
    0, // height (optional)
    -1, // quality (optional)
    output.JPG, // output (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);

