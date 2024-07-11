import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Users;
import io.appwrite.enums.MessagingProviderType;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Users users = new Users(client);

users.createTarget(
    "<USER_ID>", // userId
    "<TARGET_ID>", // targetId
    MessagingProviderType.EMAIL, // providerType
    "<IDENTIFIER>", // identifier
    "<PROVIDER_ID>", // providerId (optional)
    "<NAME>", // name (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

