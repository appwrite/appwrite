import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let messaging = Messaging(client)

let subscriberList = try await messaging.listSubscribers(
    topicId: "<TOPIC_ID>",
    queries: [], // optional
    search: "<SEARCH>" // optional
)

