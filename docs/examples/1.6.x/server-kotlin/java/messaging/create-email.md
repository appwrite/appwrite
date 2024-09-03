import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

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

