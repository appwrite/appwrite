import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let messaging = Messaging(client)

let logList = try await messaging.listSubscriberLogs(
    subscriberId: "<SUBSCRIBER_ID>",
    queries: [] // optional
)

