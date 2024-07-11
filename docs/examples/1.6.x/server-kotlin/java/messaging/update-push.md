import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.updatePush(
    "<MESSAGE_ID>", // messageId
    listOf(), // topics (optional)
    listOf(), // users (optional)
    listOf(), // targets (optional)
    "<TITLE>", // title (optional)
    "<BODY>", // body (optional)
    mapOf( "a" to "b" ), // data (optional)
    "<ACTION>", // action (optional)
    "[ID1:ID2]", // image (optional)
    "<ICON>", // icon (optional)
    "<SOUND>", // sound (optional)
    "<COLOR>", // color (optional)
    "<TAG>", // tag (optional)
    0, // badge (optional)
    false, // draft (optional)
    "", // scheduledAt (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

