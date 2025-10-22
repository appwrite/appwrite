import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createAPNSProvider(
    "<PROVIDER_ID>", // providerId
    "<NAME>", // name
    "<AUTH_KEY>", // authKey (optional)
    "<AUTH_KEY_ID>", // authKeyId (optional)
    "<TEAM_ID>", // teamId (optional)
    "<BUNDLE_ID>", // bundleId (optional)
    false, // sandbox (optional)
    false, // enabled (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

