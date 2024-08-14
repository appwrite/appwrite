import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

let messaging = Messaging(client)

let topic = try await messaging.updateTopic(
    topicId: "<TOPIC_ID>",
    name: "<NAME>", // optional
    subscribe: ["any"] // optional
)

