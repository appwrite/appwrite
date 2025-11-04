import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let databases = Databases(client)

let databaseList = try await databases.list(
    queries: [], // optional
    search: "<SEARCH>", // optional
    total: false // optional
)

