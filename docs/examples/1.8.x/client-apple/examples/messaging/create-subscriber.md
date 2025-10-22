import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let messaging = Messaging(client)

let subscriber = try await messaging.createSubscriber(
    topicId: "<TOPIC_ID>",
    subscriberId: "<SUBSCRIBER_ID>",
    targetId: "<TARGET_ID>"
)

