using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetJWT("<YOUR_JWT>"); // Your secret JSON Web Token

Messaging messaging = new Messaging(client);

await messaging.DeleteSubscriber(
    topicId: "<TOPIC_ID>",
    subscriberId: "<SUBSCRIBER_ID>"
);