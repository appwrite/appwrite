import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Avatars;
import io.appwrite.enums.Theme;
import io.appwrite.enums.Timezone;
import io.appwrite.enums.Output;

Client client = new Client(context)
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>"); // Your project ID

Avatars avatars = new Avatars(client);

avatars.getScreenshot(
    "https://example.com", // url 
    Map.of("a", "b"), // headers (optional)
    1, // viewportWidth (optional)
    1, // viewportHeight (optional)
    0.1, // scale (optional)
    Theme.LIGHT, // theme (optional)
    "<USER_AGENT>", // userAgent (optional)
    false, // fullpage (optional)
    "<LOCALE>", // locale (optional)
    Timezone.AFRICA_ABIDJAN, // timezone (optional)
    -90, // latitude (optional)
    -180, // longitude (optional)
    0, // accuracy (optional)
    false, // touch (optional)
    List.of(), // permissions (optional)
    0, // sleep (optional)
    0, // width (optional)
    0, // height (optional)
    -1, // quality (optional)
    Output.JPG, // output (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);

