import io.appwrite.Client;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Messaging;

Client client = new Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setJWT("<YOUR_JWT>"); // Your secret JSON Web Token

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

        System.out.println(result);
    })
);

