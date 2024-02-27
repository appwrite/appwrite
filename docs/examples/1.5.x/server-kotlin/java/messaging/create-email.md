import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>", // messageId
    "<SUBJECT>", // subject
    "<CONTENT>", // content
    listOf(), // topics (optional)
    listOf(), // users (optional)
    listOf(), // targets (optional)
    listOf(), // cc (optional)
    listOf(), // bcc (optional)
    listOf(), // attachments (optional)
    false, // draft (optional)
    false, // html (optional)
    "", // scheduledAt (optional)
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        System.out.println(result);
    })
);

