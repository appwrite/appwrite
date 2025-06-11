import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.updateTwilioProvider(
    "<PROVIDER_ID>", // providerId
    "<NAME>", // name (optional)
    false, // enabled (optional)
    "<ACCOUNT_SID>", // accountSid (optional)
    "<AUTH_TOKEN>", // authToken (optional)
    "<FROM>", // from (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

