import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

let messaging = Messaging(client)

let message = try await messaging.updateSms(
    messageId: "<MESSAGE_ID>",
    topics: [], // optional
    users: [], // optional
    targets: [], // optional
    content: "<CONTENT>", // optional
    draft: false, // optional
    scheduledAt: "" // optional
)

