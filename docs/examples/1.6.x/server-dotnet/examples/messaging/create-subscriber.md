using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetJWT("&lt;YOUR_JWT&gt;"); // Your secret JSON Web Token

Messaging messaging = new Messaging(client);

Subscriber result = await messaging.CreateSubscriber(
    topicId: "<TOPIC_ID>",
    subscriberId: "<SUBSCRIBER_ID>",
    targetId: "<TARGET_ID>"
);