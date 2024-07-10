import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .setKey("&lt;YOUR_API_KEY&gt;") // Your secret API key

let storage = Storage(client)

let bucketList = try await storage.listBuckets(
    queries: [], // optional
    search: "<SEARCH>" // optional
)

