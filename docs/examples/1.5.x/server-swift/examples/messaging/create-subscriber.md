import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...") // Your secret JSON Web Token

let messaging = Messaging(client)

let subscriber = try await messaging.createSubscriber(
    topicId: "<TOPIC_ID>",
    subscriberId: "<SUBSCRIBER_ID>",
    targetId: "<TARGET_ID>"
)

