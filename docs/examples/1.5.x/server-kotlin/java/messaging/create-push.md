import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createPush(
    "<MESSAGE_ID>", // messageId
    "<TITLE>", // title
    "<BODY>", // body
    listOf(), // topics (optional)
    listOf(), // users (optional)
    listOf(), // targets (optional)
    mapOf( "a" to "b" ), // data (optional)
    "<ACTION>", // action (optional)
    "[ID1:ID2]", // image (optional)
    "<ICON>", // icon (optional)
    "<SOUND>", // sound (optional)
    "<COLOR>", // color (optional)
    "<TAG>", // tag (optional)
    "<BADGE>", // badge (optional)
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

