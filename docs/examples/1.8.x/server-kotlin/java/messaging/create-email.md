import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>"); // Your secret API key

Messaging messaging = new Messaging(client);

messaging.createEmail(
    "<MESSAGE_ID>", // messageId
    "<SUBJECT>", // subject
    "<CONTENT>", // content
    List.of(), // topics (optional)
    List.of(), // users (optional)
    List.of(), // targets (optional)
    List.of(), // cc (optional)
    List.of(), // bcc (optional)
    List.of(), // attachments (optional)
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

