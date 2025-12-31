import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Avatars;
import io.appwrite.enums.Theme;
import io.appwrite.enums.Timezone;
import io.appwrite.enums.Output;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setSession(""); // The user session to authenticate with

Avatars avatars = new Avatars(client);

avatars.getScreenshot(
    "https://example.com", // url
    Map.of(
        "Authorization", "Bearer token123",
        "X-Custom-Header", "value"
    ), // headers (optional)
    1920, // viewportWidth (optional)
    1080, // viewportHeight (optional)
    2, // scale (optional)
    Theme.LIGHT, // theme (optional)
    "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15", // userAgent (optional)
    true, // fullpage (optional)
    "en-US", // locale (optional)
    Timezone.AFRICA_ABIDJAN, // timezone (optional)
    37.7749, // latitude (optional)
    -122.4194, // longitude (optional)
    100, // accuracy (optional)
    true, // touch (optional)
    List.of("geolocation", "notifications"), // permissions (optional)
    3, // sleep (optional)
    800, // width (optional)
    600, // height (optional)
    85, // quality (optional)
    Output.JPG, // output (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

