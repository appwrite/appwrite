import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let messaging = Messaging(client)

let topic = try await messaging.updateTopic(
    topicId: "<TOPIC_ID>",
    name: "<NAME>", // optional
    subscribe: ["any"] // optional
)

