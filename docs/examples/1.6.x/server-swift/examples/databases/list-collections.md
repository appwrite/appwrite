import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let databases = Databases(client)

let collectionList = try await databases.listCollections(
    databaseId: "<DATABASE_ID>",
    queries: [], // optional
    search: "<SEARCH>" // optional
)

