import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client(context)
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2"); // Your project ID

Messaging messaging = new Messaging(client);

messaging.createSubscriber(
    "<TOPIC_ID>", // topicId 
    "<SUBSCRIBER_ID>", // subscriberId 
    "<TARGET_ID>", // targetId 
    new CoroutineCallback<>((result, error) -> {
        if (error != null) {
            error.printStackTrace();
            return;
        }

        Log.d("Appwrite", result.toString());
    })
);

