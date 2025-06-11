import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.updateAPNSProvider(
    "[PROVIDER_ID]", // providerId
    "[NAME]", // name (optional)
    false, // enabled (optional)
    "[AUTH_KEY]", // authKey (optional)
    "[AUTH_KEY_ID]", // authKeyId (optional)
    "[TEAM_ID]", // teamId (optional)
    "[BUNDLE_ID]", // bundleId (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

